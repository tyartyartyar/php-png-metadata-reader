<?php

declare(strict_types=1);

namespace PNGMetadataReader;

use ArrayObject;

class PNGMetadataReader extends ArrayObject
{
	/** The file path PNG */
	private ?string $path;

	/**
	 * @var mixed[]
	 */
	private array $metadata = [];

	/**
	 * @var resource|null
	 */
	private $exif_data;

	/**
	 * @var mixed[]
	 */
	private array $chunks = [];

	/**
	 * @param string $path Location of the image in disk.
	 * @param string $chunk_type chunk type of a binary file
	 */
	public function __construct(string $path, string $chunk_type = null)
	{
		$this->checkPath($path);
		$this->extractChunks();
		$this->extractExif($chunk_type);
		ksort($this->metadata);

		parent::__construct($this->metadata);
	}


	/**
	 * @param string $path Location of the image in disk.
	 * @param string $chunk_type chunk type of a binary file
	 * @return PNGMetadataReader|null
	 */
	public static function extract(?string $path = null, string $chunk_type = null): ?self
	{
		try {
			return new self($path, $chunk_type);
		} catch (\Throwable $e) {
			return null;
		}
	}


	/**
	 * Return metadata as array.
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->metadata;
	}


	/**
	 * Check if file path is a PNG.
	 *
	 * @param  string $path Location of the image in disk.
	 */
	public static function isPNG(string $path): bool
	{
		return self::getType($path) == IMAGETYPE_PNG;
	}


	/**
	 * @param  string $path Location of the image in disk.
	 * @return int|false
	 */
	public static function getType(string $path)
	{
		if (!$path) {
			throw new \InvalidArgumentException('Path is required', 101);
		} elseif (!file_exists($path)) {
			throw new \InvalidArgumentException('File not found', 102);
		}

		return exif_imagetype($path);
	}


	/**
	 * Check the file path and store it.
	 */
	private function checkPath(string $path): void
	{
		if ($this->isPNG($path)) {
			$this->path = $path;
		} else {
			throw new \InvalidArgumentException('File is not a PNG', 103);
		}
	}


	/**
	 * Extract the data chunks
	 */
	private function extractChunks(): void
	{
		$content = fopen($this->path, 'rb');
		if (fread($content, 8) !== "\x89PNG\x0d\x0a\x1a\x0a") {
			throw new \InvalidArgumentException('Invalid PNG file signature', 104);
		}

		$chunkHeader = fread($content, 8);
		while ($chunkHeader) {
			$chunk = unpack('Nsize/a4type', $chunkHeader);
			if ($chunk['type'] === 'IEND') {
				break;
			}
			if ($chunk['type'] === 'tEXt') {
				$this->chunks[$chunk['type']][] = explode("\0", fread($content, $chunk['size']));
				fseek($content, 4, SEEK_CUR);
			} else {
				if (
					$chunk['type'] === 'eXIf'
					|| $chunk['type'] === 'sRGB'
					|| $chunk['type'] === 'iTXt'
					|| $chunk['type'] === 'bKGD'
				) {
					$lastOffset = ftell($content);
					$this->chunks[$chunk['type']] = fread($content, $chunk['size']);
					fseek($content, $lastOffset, SEEK_SET);
				} elseif ($chunk['type'] === 'IHDR') {
					$lastOffset = ftell($content);
					for ($i = 0; $i < 6; $i++) {
						$this->chunks[$chunk['type']][] = fread($content, ($i > 1 ? 1 : 4));
					}
					fseek($content, $lastOffset, SEEK_SET);
				}
				fseek($content, $chunk['size'] + 4, SEEK_CUR);
			}
			$chunkHeader = fread($content, 8);
		}
		fclose($content);
	}


	/**
	 * Extract Exif data from eXIf chunk
	 */
	private function extractExif(string $chunk_type = null): void
	{
		if (isset($this->chunks['eXIf'])) {
			$this->exif_data = fopen('php://memory', 'r+b');
			fwrite($this->exif_data, $this->chunks['eXIf']);
			rewind($this->exif_data);

			$this->metadata['exif'] = array_replace(
				$this->metadata['exif'] ?? [],
				exif_read_data($this->exif_data, $chunk_type, true),
			);

			rewind($this->exif_data);
		}
	}

}
