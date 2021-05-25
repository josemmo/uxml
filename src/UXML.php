<?php
namespace UXML;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use function count;
use function preg_replace_callback;
use function strpos;

class UXML {
    const NS_PREFIX = "__uxml_ns_";
    /** @var DOMElement */
    protected $element;

    /**
     * Create instance from XML string
     * @param  string $xmlString XML string
     * @return self              Root XML element
     * @throws InvalidArgumentException if failed to parse XML
     */
    public static function fromString(string $xmlString): self {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if ($doc->loadXML($xmlString) === false) {
            throw new InvalidArgumentException('Failed to parse XML string');
        }
        return new self($doc->documentElement);
    }


    /**
     * Create instance from DOM element
     * @param  DOMElement $element DOM element
     * @return self                Wrapped element as a UXML instance
     * @suppress PhanUndeclaredProperty
     */
    public static function fromElement(DOMElement $element): self {
        return $element->uxml ?? new self($element);
    }


    /**
     * Create new instance
     * @param  string           $name  Element tag name
     * @param  string|null      $value Element value or NULL for empty
     * @param  array            $attrs Element attributes
     * @param  DOMDocument|null $doc   Document instance
     * @return self                    New instamce
     */
    public static function newInstance(string $name, ?string $value=null, array $attrs=[], DOMDocument $doc=null): self {
        $targetDoc = ($doc === null) ? new DOMDocument() : $doc;
        $domElement = $targetDoc->createElement($name);
        $domElement->textContent = $value;

        // Set attributes
        foreach ($attrs as $attrName=>$attrValue) {
            if ($attrName === "xmlns" || strpos($attrName, 'xmlns:') === 0) {
                $domElement->setAttributeNS('http://www.w3.org/2000/xmlns/', $attrName, $attrValue);
            } else {
                $domElement->setAttribute($attrName, $attrValue);
            }
        }

        // Create instance
        return new self($domElement);
    }


    /**
     * Class constructor
     * @param DOMElement $element DOM Element instance
     * @suppress PhanUndeclaredProperty
     */
    private function __construct(DOMElement $element) {
        $this->element = $element;
        $this->element->uxml = $this;
    }


    /**
     * Get DOM element instance
     * @return DOMElement DOM element instance
     */
    public function element(): DOMElement {
        return $this->element;
    }


    /**
     * Get parent element
     * @return self Parent element instance or this instance if it has no parent
     */
    public function parent(): self {
        $parentNode = $this->element->parentNode;
        return ($parentNode !== null && $parentNode instanceof DOMElement) ? self::fromElement($parentNode) : $this;
    }


    /**
     * Is empty
     * @return boolean TRUE if the element has no inner content, FALSE otherwise
     */
    public function isEmpty(): bool {
        return ($this->element->childNodes->length === 0);
    }


    /**
     * Add child element
     * @param  string      $name  New element tag name
     * @param  string|null $value New element value or NULL for empty
     * @param  array       $attrs New element attributes
     * @return self               New element instance
     */
    public function add(string $name, ?string $value=null, array $attrs=[]): self {
        $child = self::newInstance($name, $value, $attrs, $this->element->ownerDocument);
        $this->element->appendChild($child->element);
        return $child;
    }


    /**
     * Find elements
     * @param  string   $xpath XPath query relative to this element
     * @param  int|null $limit Maximum number of results to return
     * @return self[]          Matched elements
     */
    public function getAll(string $xpath, ?int $limit=null): array {
        $namespaces = [];
        $xpath = preg_replace_callback('/{(.+?)}/', static function($match) use (&$namespaces) {
            $ns = $match[1];
            if (!isset($namespaces[$ns])) {
                $namespaces[$ns] = self::NS_PREFIX . count($namespaces);
            }
            return $namespaces[$ns] . ":";
        }, $xpath);

        // Create instance
        $xpathInstance = new DOMXPath($this->element->ownerDocument);
        foreach ($namespaces as $ns=>$prefix) {
            $xpathInstance->registerNamespace($prefix, $ns);
        }

        // Parse results
        $res = [];
        $domNodes = $xpathInstance->query($xpath, $this->element);
        foreach ($domNodes as $domNode) {
            if (!$domNode instanceof DOMElement) continue;
            $res[] = self::fromElement($domNode);
            if (($limit !== null) && (--$limit <= 0)) break;
        }

        return $res;
    }


    /**
     * Find one element
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
        $this->element->parentNode->removeChild($this->element);
    }


    /**
     * Export element and children as text
     * @return string Text representation
     */
    public function asText(): string {
        return $this->element->textContent;
    }


    /**
     * Export as XML string
     * @param  string|null $version  Document version, NULL for no declaration
     * @param  string      $encoding Document encoding
     * @param  boolean     $format   Format output
     * @return string                XML string
     */
    public function asXML(?string $version="1.0", string $encoding="UTF-8", bool $format=true): string {
        $doc = new DOMDocument($version, $encoding);
        $doc->formatOutput = $format;
        $doc->appendChild($doc->importNode($this->element, true));
        $res = ($version === null) ? $doc->saveXML($doc->documentElement) : $doc->saveXML();
        unset($doc);
        return $res;
    }


    /**
     * @inheritdoc
     */
    public function __toString(): string {
        return $this->asXML(null, '', false);
    }
}
