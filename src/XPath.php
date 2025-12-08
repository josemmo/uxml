<?php
namespace UXML;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Generator;

use const XML_ELEMENT_NODE;

use function explode;
use function count;
use function preg_match;
use function preg_replace_callback;
use function strpos;

class XPath {
    const NS_PREFIX = '__uxml_ns_';

    /** @var DOMXPath */
    private $instance;

    /**
     * Registered namespaces, keys are namespaces and values are prefixes
     * 
     * @var array<string,string>
     */
    private $namespaces = [];

    /**
     * Inverse of registered namespaces, keys are prefixes and values are namespaces
     * 
     * @var array<string,string>
     */
    private $prefixes = [];

    /**
     * Class constructor
     * 
     * @param DOMDocument $document DOM Document instance
     */
    public function __construct(DOMDocument $document) {
        $this->instance = new DOMXPath($document);
    }

    /**
     * Run query
     * 
     * @param  string                $query   XPath query
     * @param  DOMElement            $context DOM Element to use for relative queries
     * @return Generator<DOMElement>          Generator of DOM elements
     */
    public function query(string $query, DOMElement $context): Generator {
        $query = $this->parseQuery($query);

        // Try to use fastest query engine
        // - No start with slash
        // - No double slash
        // - No double colon
        // - No special characters
        if (preg_match('/^\/|\/\/|::|[[|*.@]/', $query) === 0) {
            return $this->queryWithChildNodes($query, $context);
        }

        // Fallback to slower, native XPath engine
        return $this->queryWithXpath($query, $context);
    }

    /**
     * Parse query
     * 
     * @param  string $rawQuery Raw XPath query with Clark notation
     * @return string           XPath query
     */
    private function parseQuery(string $rawQuery): string {
        // Skip if no Clark notation is found
        if (strpos($rawQuery, '{') === false) {
            return $rawQuery;
        }

        // Replace namespaces
        return preg_replace_callback('/{(\S+?)}/', function (array $match): string { // @phpstan-ignore return.type
            $namespace = $match[1];
            if (!isset($this->namespaces[$namespace])) {
                $prefix = self::NS_PREFIX . count($this->namespaces);
                $this->namespaces[$namespace] = $prefix;
                $this->prefixes[$prefix] = $namespace;
                $this->instance->registerNamespace($prefix, $namespace);
            }
            return "{$this->namespaces[$namespace]}:";
        }, $rawQuery);
    }

    /**
     * Run query using native XPath
     * 
     * @param  string                $query   XPath query
     * @param  DOMElement            $context DOM Element to use for relative queries
     * @return Generator<DOMElement>          Generator of DOM elements
     */
    private function queryWithXpath(string $query, DOMElement $context): Generator {
        /** @var DOMNodeList<DOMNode> */
        $domNodes = $this->instance->query($query, $context);
        foreach ($domNodes as $domNode) {
            if ($domNode->nodeType === XML_ELEMENT_NODE) {
                /** @var DOMElement $domNode */
                yield $domNode;
            }
        }
    }

    /**
     * Run query using child nodes traversal
     * 
     * @param  string                $query   Simple query in the form of "prefix:element/child"
     * @param  DOMElement            $context DOM element to use for relatives queries
     * @return Generator<DOMElement>          Generator of DOM elements
     */
    private function queryWithChildNodes(string $query, DOMElement $context): Generator {
        /** @var DOMElement[] */
        $currentQueue = [$context];
        foreach (explode('/', $query) as $segment) {
            // Parse namespace and element name
            $segmentParts = explode(':', $segment, 2);
            if (isset($segmentParts[1])) {
                [$prefix, $elementName] = $segmentParts;
                $namespace = $this->prefixes[$prefix] ?? $this->instance->document->lookupNamespaceURI($prefix);
            } else {
                $namespace = null;
                $elementName = $segmentParts[0];
            }

            // Find child matches
            /** @var DOMElement[] */
            $nextQueue = [];
            foreach ($currentQueue as $parent) {
                foreach ($parent->childNodes as $child) {
                    if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                    if ($child->localName !== $elementName) continue;
                    if ($child->namespaceURI !== $namespace) continue;
                    /** @var DOMElement $child */
                    $nextQueue[] = $child;
                }
            }

            // Prepare next iteration
            if (count($nextQueue) === 0) {
                return;
            }
            $currentQueue = $nextQueue;
        }

        yield from $currentQueue;
    }
}
