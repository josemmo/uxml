<?php
namespace Tests;

use DOMElement;
use UXML\UXML;
use PHPUnit\Framework\TestCase;

final class UXMLTest extends TestCase {
    /**
     * Element list to text
     * @param  UXML[] $elements Array of elements
     * @return string           Text content of elements separated by commas
     */
    private function listToText(array $elements): string {
        return implode(',', array_map(function(UXML $elem) {
            return $elem->asText();
        }, $elements));
    }

    public function testCanCreateElements(): void {
        $xml = UXML::newInstance('RootTagName');
        $this->assertEquals('<RootTagName/>', $xml);

        $xml = UXML::fromElement(new DOMElement('TagName'));
        $this->assertEquals('<TagName/>', $xml);
    }

    public function testCanLoadXml(): void {
        $source  = "<fruits>";
        $source .= "<fruit>Banana</fruit>";
        $source .= "<fruit>Apple</fruit>";
        $source .= "<fruit>Orange</fruit>";
        $source .= "<optional>";
        $source .= "<fruit>Tomato</fruit>";
        $source .= "</optional>";
        $source .= "</fruits>";
        $xml = UXML::load($source);
        $this->assertEquals($source, $xml);
    }

    public function testCanAddElements(): void {
        $xml = UXML::newInstance('Parent');
        $childA = $xml->add('ChildA', 'Name');
        $childB = $xml->add('Wrapper')->add('ChildB');
        $this->assertEquals('<ChildA>Name</ChildA>', $childA);
        $this->assertEquals('<ChildB/>', $childB);
        $this->assertEquals('<Parent><ChildA>Name</ChildA><Wrapper><ChildB/></Wrapper></Parent>', $xml);
    }

    public function testCanHandleAttributes(): void {
        $feed = UXML::newInstance('feed', null, [
            'xmlns' => 'urn:atom',
            'xmlns:a' => 'urn:testns'
        ]);
        $feed->add('link', 'Wow!', ['a:href' => 'urn']);
        $this->assertEquals('<feed xmlns="urn:atom" xmlns:a="urn:testns"><link a:href="urn">Wow!</link></feed>', $feed);
    }

    public function testCanGetSingleElement(): void {
        $source = <<<XML
        <movie>
            <name lang="en-US">Inception</name>
            <year>2010</year>
            <director>
                <name>Christopher</name>
                <surname>Nolan</surname>
                <year>1970</year>
            </director>
        </movie>
        XML;
        $xml = UXML::load($source);

        $this->assertEquals('<year>1970</year>', $xml->get('director/year'));
        $this->assertEquals('<year>1970</year>', $xml->get('director')->get('year'));
        $this->assertEquals('<year>2010</year>', $xml->get('year'));
        $this->assertEquals('<year>2010</year>', $xml->get('director')->get('//year'));
        $this->assertEquals('<name lang="en-US">Inception</name>', $xml->get('*[@lang]'));
        $this->assertNull($xml->get('genre'));
    }

    public function testCanGetAllElements(): void {
        $source = <<<XML
            <root>
                <a>
                    <b>1</b>
                    <b>2</b>
                    <c>-1</c>
                    <b>3</b>
                    <c>-2</c>
                    <d>Inf</d>
                </a>
                <b>4</b>
                <c>-3</c>
            </root>
        XML;
        $xml = UXML::load($source);

        $this->assertEquals('1,2,3',    $this->listToText($xml->getAll('a/b')));
        $this->assertEquals('1,2',      $this->listToText($xml->getAll('a/b', 2)));
        $this->assertEquals('-3',       $this->listToText($xml->getAll('c')));
        $this->assertEquals('-1,-2,-3', $this->listToText($xml->getAll('//c')));
        $this->assertEmpty($xml->getAll('d'));
    }

    public function testCanHandleClarkNotation(): void {
        $xml = UXML::load('<a xmlns:ns="urn:abc"><ns:b /><ns:c /></a>');
        $this->assertEquals('<ns:b xmlns:ns="urn:abc"/>', $xml->get('{urn:abc}b'));
        $this->assertSame($xml->get('{urn:abc}b'), $xml->get('ns:b'));
    }
}
