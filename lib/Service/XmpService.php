<?php

declare(strict_types=1);

namespace OCA\Stoffe\Service;

/**
 * Reads and writes the fabric fields as XMP inside JPEG files (APP1 segment).
 *
 * Fields live in their own namespace (prefix "stoffe"); the comment is also
 * written to dc:description so other photo tools show it. Existing XMP data
 * from cameras or other tools is preserved.
 */
class XmpService {
	public const NS = 'http://ns.stoffe-app.de/1.0/';
	public const FIELDS = ['room', 'cabinet', 'shelf', 'price', 'fabricType', 'category', 'comment'];

	private const NS_X = 'adobe:ns:meta/';
	private const NS_RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';
	private const NS_XML = 'http://www.w3.org/XML/1998/namespace';
	private const XMP_HEADER = "http://ns.adobe.com/xap/1.0/\0";
	private const MAX_SEGMENT = 65533;

	/**
	 * @param resource $stream JPEG data, read until the image data starts
	 * @return array<string,string>|null null if the file holds no fabric data
	 */
	public function read($stream): ?array {
		$xml = $this->findPacket($stream);
		if ($xml === null) {
			return null;
		}
		$doc = $this->loadXml($xml);
		if ($doc === null) {
			return null;
		}
		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('rdf', self::NS_RDF);
		$xpath->registerNamespace('s', self::NS);

		$values = [];
		$found = false;
		foreach (self::FIELDS as $field) {
			$node = $xpath->query("//rdf:Description/@s:$field | //rdf:Description/s:$field")->item(0);
			$values[$field] = $node === null ? '' : trim($node->textContent);
			$found = $found || $node !== null;
		}
		return $found ? $values : null;
	}

	/**
	 * Returns the JPEG bytes with the fabric fields embedded.
	 *
	 * @param array<string,string> $values
	 */
	public function write(string $jpeg, array $values): string {
		if (!str_starts_with($jpeg, "\xFF\xD8")) {
			throw new \InvalidArgumentException('Keine gültige JPEG-Datei.');
		}
		[$segments, $rest] = $this->splitSegments($jpeg);

		$existing = null;
		$insertAt = 0;
		foreach ($segments as $i => $segment) {
			if ($segment['marker'] === 0xE1 && str_starts_with($segment['data'], self::XMP_HEADER)) {
				$existing = $i;
			}
			// XMP goes after JFIF/Exif (APP0/APP1), before everything else.
			if ($segment['marker'] === 0xE0 || $segment['marker'] === 0xE1) {
				$insertAt = $i + 1;
			}
		}

		$oldXml = $existing === null ? null : substr($segments[$existing]['data'], strlen(self::XMP_HEADER));
		$data = self::XMP_HEADER . $this->buildPacket($oldXml, $values);
		if (strlen($data) > self::MAX_SEGMENT) {
			throw new \RuntimeException('Die Bilddaten (XMP) sind zu groß.');
		}
		$segment = ['marker' => 0xE1, 'data' => $data];
		if ($existing !== null) {
			$segments[$existing] = $segment;
		} else {
			array_splice($segments, $insertAt, 0, [$segment]);
		}

		$out = "\xFF\xD8";
		foreach ($segments as $s) {
			$out .= "\xFF" . chr($s['marker']) . pack('n', strlen($s['data']) + 2) . $s['data'];
		}
		return $out . $rest;
	}

	/**
	 * @return array{0: list<array{marker:int,data:string}>, 1: string}
	 */
	private function splitSegments(string $jpeg): array {
		$segments = [];
		$pos = 2;
		$len = strlen($jpeg);
		while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
			$marker = ord($jpeg[$pos + 1]);
			if ($marker === 0xDA || $marker === 0xD9) {
				break; // start of scan / end of image: the rest is copied unchanged
			}
			$size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
			$segments[] = ['marker' => $marker, 'data' => substr($jpeg, $pos + 4, $size - 2)];
			$pos += 2 + $size;
		}
		return [$segments, substr($jpeg, $pos)];
	}

	/**
	 * @param resource $stream
	 */
	private function findPacket($stream): ?string {
		if (fread($stream, 2) !== "\xFF\xD8") {
			return null;
		}
		while (!feof($stream)) {
			$head = fread($stream, 4);
			if ($head === false || strlen($head) < 4 || $head[0] !== "\xFF") {
				return null;
			}
			$marker = ord($head[1]);
			if ($marker === 0xDA || $marker === 0xD9) {
				return null;
			}
			$size = unpack('n', substr($head, 2, 2))[1] - 2;
			$data = $size > 0 ? stream_get_contents($stream, $size) : '';
			if ($marker === 0xE1 && str_starts_with($data, self::XMP_HEADER)) {
				return substr($data, strlen(self::XMP_HEADER));
			}
		}
		return null;
	}

	private function loadXml(string $xml): ?\DOMDocument {
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$ok = $doc->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $ok ? $doc : null;
	}

	/**
	 * @param array<string,string> $values
	 */
	private function buildPacket(?string $oldXml, array $values): string {
		$doc = $oldXml === null ? null : $this->loadXml($oldXml);
		$rdf = $doc?->getElementsByTagNameNS(self::NS_RDF, 'RDF')->item(0);
		if ($doc === null || $rdf === null) {
			$doc = new \DOMDocument('1.0', 'UTF-8');
			$meta = $doc->appendChild($doc->createElementNS(self::NS_X, 'x:xmpmeta'));
			$rdf = $meta->appendChild($doc->createElementNS(self::NS_RDF, 'rdf:RDF'));
		}

		// Drop our previous values (and dc:description, which we own as "comment").
		foreach (iterator_to_array($doc->getElementsByTagNameNS(self::NS_RDF, 'Description')) as $desc) {
			foreach (iterator_to_array($desc->attributes) as $attr) {
				if ($attr->namespaceURI === self::NS) {
					$desc->removeAttributeNode($attr);
				}
			}
			foreach (iterator_to_array($desc->childNodes) as $child) {
				if ($child->namespaceURI === self::NS || ($child->namespaceURI === self::NS_DC && $child->localName === 'description')) {
					$desc->removeChild($child);
				}
			}
			if (!$desc->hasChildNodes() && $desc->attributes->length <= 1) {
				$desc->parentNode->removeChild($desc);
			}
		}

		$desc = $rdf->appendChild($doc->createElementNS(self::NS_RDF, 'rdf:Description'));
		$desc->setAttributeNS(self::NS_RDF, 'rdf:about', '');
		$desc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:stoffe', self::NS);
		$desc->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::NS_DC);
		foreach (self::FIELDS as $field) {
			$el = $desc->appendChild($doc->createElementNS(self::NS, 'stoffe:' . $field));
			$el->appendChild($doc->createTextNode($values[$field] ?? ''));
		}
		if (($values['comment'] ?? '') !== '') {
			$descr = $desc->appendChild($doc->createElementNS(self::NS_DC, 'dc:description'));
			$alt = $descr->appendChild($doc->createElementNS(self::NS_RDF, 'rdf:Alt'));
			$li = $alt->appendChild($doc->createElementNS(self::NS_RDF, 'rdf:li', ''));
			$li->setAttributeNS(self::NS_XML, 'xml:lang', 'x-default');
			$li->appendChild($doc->createTextNode($values['comment']));
		}

		$body = $doc->saveXML($doc->documentElement);
		return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n" . $body
			. "\n" . str_repeat(' ', 1024) . "\n<?xpacket end=\"w\"?>";
	}
}
