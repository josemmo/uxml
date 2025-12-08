<?php
namespace UXML;

use DOMDocument;
use DOMElement;
use DOMException;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use InvalidArgumentException;
use WeakMap;

use function class_exists;
use function count;
use function preg_replace_callback;
use function strpos;
use function strstr;

class UXML {
    const NS_PREFIX = '__uxml_ns_';

    /**
     * DOMElement instances
     * 
     * Map of DOMElement references used from PHP 8.0 to avoid "Creation of dynamic property" deprecation warning.
     * In previous versions, a custom DOMElement::$uxml property is used to keep a reference to the UXML instance.
     * 
     * @var WeakMap<DOMElement,self>|null|false
     */
    private static $elements = null; // @phpstan-ignore class.notFound

    /** @var DOMElement */
    protected $element;

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
     * Class constructor
     * 
     * @param DOMElement $element DOM Element instance
     */
    private function __construct(DOMElement $element) {
        // Keep reference of DOMElement instance
        $this->element = $element;

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
        return ($parentNode !== null && $parentNode instanceof DOMElement) ? self::fromElement($parentNode) : $this;
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
        $child = self::newInstance($name, $value, $attrs, $this->element->ownerDocument);
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
        $namespaces = [];
        /** @var string */
        $xpath = preg_replace_callback('/{(.+?)}/', static function($match) use (&$namespaces) {
            $ns = $match[1];
            if (!isset($namespaces[$ns])) {
                $namespaces[$ns] = self::NS_PREFIX . count($namespaces);
            }
            return $namespaces[$ns] . ':';
        }, $xpath);

        // Create instance
        $xpathInstance = new DOMXPath($this->element->ownerDocument); // @phpstan-ignore argument.type
        foreach ($namespaces as $ns=>$prefix) {
            $xpathInstance->registerNamespace($prefix, $ns);
        }

        // Parse results
        $res = [];
        /** @var DOMNodeList<DOMNode> */
        $domNodes = $xpathInstance->query($xpath, $this->element);
        foreach ($domNodes as $domNode) {
            if (!$domNode instanceof DOMElement) continue;
            $res[] = self::fromElement($domNode);
            if ($limit !== null && --$limit <= 0) break;
        }

        return $res;
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
