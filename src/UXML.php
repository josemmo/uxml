<?php
namespace UXML;

use DOMDocument;
use DOMElement;
use DOMException;
use InvalidArgumentException;
use WeakMap;

use const PHP_INT_MAX;
use const XML_ELEMENT_NODE;

use function class_exists;
use function strpos;
use function strstr;

class UXML {
    /**
     * DOMElement instances
     * 
     * Map of DOMElement references used from PHP 8.0 to avoid "Creation of dynamic property" deprecation warning.
     * In previous versions, a custom DOMElement::$uxml property is used to keep a reference to the UXML instance.
     * 
     * @var WeakMap<DOMElement,self>|null|false
     */
    private static $elements = null; // @phpstan-ignore class.notFound

    /**
     * XPath instances
     * 
     * Map of XPath instances indexed by DOMDocument. As with `self::$elements`, this uses a WeakMap if available
     * or a custom DOMDocument::$xpath property in legacy versions.
     * 
     * Used for performance purposes.
     * 
     * @var WeakMap<DOMDocument,XPath>|null|false
     */
    private static $xpaths = null; // @phpstan-ignore class.notFound

    /** @var DOMElement */
    protected $element;

    /**
     * Reference to the DOM document to which this element belongs, to stop PHP from garbage collecting it.
     * @var DOMDocument
     */
    protected $document;

    /**
     * Create instance from XML string
     * 
     * @param  string $xmlString XML string
     * @return self              Root XML element
     * @throws InvalidArgumentException if failed to parse XML
     */
    public static function fromString(string $xmlString): self {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (@$doc->loadXML($xmlString) === false) {
            throw new InvalidArgumentException('Failed to parse XML string');
        }
        if ($doc->documentElement === null) {
            throw new InvalidArgumentException('Document element not found');
        }
        return new self($doc->documentElement);
    }

    /**
     * Create instance from DOM element
     * 
     * @param  DOMElement $element DOM element
     * @return self                Wrapped element as a UXML instance
     */
    public static function fromElement(DOMElement $element): self {
        $cachedElements = self::getCachedElements();

        // Fallback to dynamic properties
        if ($cachedElements === false) {
            return $element->uxml ?? new self($element); // @phpstan-ignore property.notFound
        }

        // Use WeakMap when supported
        return $cachedElements->offsetExists($element) ? // @phpstan-ignore class.notFound
            $cachedElements->offsetGet($element) : // @phpstan-ignore class.notFound
            new self($element);
    }

    /**
     * Create new instance
     * 
     * @param  string               $name  Element tag name
     * @param  string|null          $value Element value or `null` for empty
     * @param  array<string,string> $attrs Element attributes
     * @param  DOMDocument|null     $doc   Document instance
     * @return self                        New instance
     * @throws DOMException if failed to create new instance
     */
    public static function newInstance(string $name, ?string $value=null, array $attrs=[], ?DOMDocument $doc=null): self {
        $targetDoc = ($doc === null) ? new DOMDocument() : $doc;

        // Get namespace
        $prefix = strstr($name, ':', true) ?: ''; // @phpstan-ignore ternary.shortNotAllowed
        $namespace = $attrs[($prefix === '') ? 'xmlns' : "xmlns:$prefix"] ?? $targetDoc->lookupNamespaceUri($prefix); // @phpstan-ignore method.nameCase

        // Create element
        $domElement = ($namespace === null) ?
            $targetDoc->createElement($name) :
            $targetDoc->createElementNS($namespace, $name);
        if ($domElement === false) {
            throw new DOMException('Failed to create DOMElement');
        }

        // Append element to document (in case of new document)
        if ($doc === null) {
            $targetDoc->appendChild($domElement);
        }

        // Set content
        if ($value !== null) {
            $domElement->textContent = $value;
        }

        // Set attributes
        foreach ($attrs as $attrName=>$attrValue) {
            if ($attrName === 'xmlns' || strpos($attrName, 'xmlns:') === 0) {
                $domElement->setAttributeNS('http://www.w3.org/2000/xmlns/', $attrName, $attrValue);
            } else {
                $domElement->setAttribute($attrName, $attrValue);
            }
        }

        // Create instance
        return new self($domElement);
    }

    /**
     * Get cached elements
     * 
     * @return WeakMap<DOMElement,self>|false Weak map of cached elements or `false` if not supported
     */
    private static function getCachedElements() { // @phpstan-ignore class.notFound
        if (self::$elements === null) {
            self::$elements = class_exists(WeakMap::class) ? new WeakMap() : false; // @phpstan-ignore assign.propertyType
        }
        return self::$elements; // @phpstan-ignore return.type
    }

    /**
     * Get or create DOMXPath instance
     * 
     * @param  DOMDocument $document DOM Document instance 
     * @return XPath                 XPath instance
     */
    private static function getOrCreateXpath(DOMDocument $document): XPath {
        if (self::$xpaths === null) {
            self::$xpaths = class_exists(WeakMap::class) ? new WeakMap() : false; // @phpstan-ignore assign.propertyType
        }

        // Fallback to dynamic properties
        if (self::$xpaths === false) {
            if (!isset($document->uxmlXpath)) { // @phpstan-ignore property.notFound
                $document->uxmlXpath = new XPath($document); // @phpstan-ignore property.notFound
            }
            return $document->uxmlXpath; // @phpstan-ignore property.notFound
        }

        // Use WeakMap when supported
        if (!self::$xpaths->offsetExists($document)) { // @phpstan-ignore class.notFound
            self::$xpaths->offsetSet($document, new XPath($document)); // @phpstan-ignore class.notFound
        }
        return self::$xpaths->offsetGet($document); // @phpstan-ignore return.type
    }

    /**
     * Class constructor
     * 
     * @param DOMElement $element DOM Element instance
     */
    private function __construct(DOMElement $element) {
        $this->element = $element;
        $this->document = $element->ownerDocument; // @phpstan-ignore assign.propertyType

        // Keep reference of UXML instance
        $cachedElements = self::getCachedElements();
        if ($cachedElements === false) {
            $this->element->uxml = $this; // @phpstan-ignore property.notFound
        } else {
            $cachedElements->offsetSet($this->element, $this); // @phpstan-ignore class.notFound
        }
    }

    /**
     * Get DOM element instance
     * 
     * @return DOMElement DOM element instance
     */
    public function element(): DOMElement {
        return $this->element;
    }

    /**
     * Get parent element
     * 
     * @return self Parent element instance or this instance if it has no parent
     */
    public function parent(): self {
        $parentNode = $this->element->parentNode;

        // Has a parent node which is a DOM element
        if ($parentNode !== null && $parentNode->nodeType === XML_ELEMENT_NODE) {
            /** @var DOMElement $parentNode */
            return self::fromElement($parentNode);
        }

        // Has no valid parent
        return $this;
    }

    /**
     * Is empty
     * 
     * @return boolean `true` if the element has no inner content, `false` otherwise
     */
    public function isEmpty(): bool {
        return !$this->element->hasChildNodes();
    }

    /**
     * Add child element
     * 
     * @param  string               $name  New element tag name
     * @param  string|null          $value New element value or `null` for empty
     * @param  array<string,string> $attrs New element attributes
     * @return self                        New element instance
     * @throws DOMException if failed to create child element
     */
    public function add(string $name, ?string $value=null, array $attrs=[]): self {
        $child = self::newInstance($name, $value, $attrs, $this->document);
        $this->element->appendChild($child->element);
        return $child;
    }

    /**
     * Find elements
     * 
     * @param  string   $xpath XPath query relative to this element
     * @param  int|null $limit Maximum number of results to return
     * @return self[]          Matched elements
     */
    public function getAll(string $xpath, ?int $limit=null): array {
        $xpathInstance = self::getOrCreateXpath($this->document);

        // Parse results
        $results = [];
        $limit = ($limit === null) ? PHP_INT_MAX : $limit;
        foreach ($xpathInstance->query($xpath, $this->element) as $domElement) {
            $results[] = self::fromElement($domElement);
            if (--$limit <= 0) {
                break;
            }
        }

        return $results;
    }

    /**
     * Find one element
     * 
     * @param  string    $xpath XPath query relative to this element
     * @return self|null        First matched element or NULL if not found
     */
    public function get(string $xpath): ?self {
        $res = $this->getAll($xpath, 1);
        return $res[0] ?? null;
    }

    /**
     * Remove this element
     * 
     * After calling this method on an instance it will become unusable.
     * Calling it on a root element will have no effect.
     */
    public function remove(): void {
        $parent = $this->element->parentNode;
        if ($parent !== null) {
            $parent->removeChild($this->element);
        }
    }

    /**
     * Export element and children as text
     * 
     * @return string Text representation
     */
    public function asText(): string {
        return $this->element->textContent;
    }

    /**
     * Export as XML string
     * 
     * @param  string|null $version  Document version, `null` for no declaration
     * @param  string      $encoding Document encoding
     * @param  boolean     $format   Format output
     * @return string                XML string
     */
    public function asXML(?string $version='1.0', string $encoding='UTF-8', bool $format=true): string {
        $doc = new DOMDocument();

        // Define document properties
        if ($version === null) {
            $doc->xmlStandalone = true;
        } else {
            $doc->xmlVersion = $version;
        }
        $doc->encoding = $encoding;
        $doc->formatOutput = $format;

        // Export XML string
        $rootNode = $doc->importNode($this->element, true);
        if ($rootNode !== false) {
            $doc->appendChild($rootNode);
        }
        /** @var string */
        $res = ($version === null) ? $doc->saveXML($doc->documentElement) : $doc->saveXML();
        unset($doc);

        return $res;
    }

    /**
     * @inheritdoc
     */
    public function __toString(): string {
        return $this->asXML(null, 'UTF-8', false);
    }
}
