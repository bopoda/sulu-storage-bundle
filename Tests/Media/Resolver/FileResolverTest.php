<?php

namespace PB\Bundle\SuluStorageBundle\Tests\Media\Resolver;

use PB\Bundle\SuluStorageBundle\Media\Resolver\FileResolver;
use PB\Bundle\SuluStorageBundle\Provider\Filesystem\FilesystemProviderInterface;
use PB\Bundle\SuluStorageBundle\Tests\Library\Utils\Reflection;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sulu\Component\PHPCR\PathCleanupInterface;

/**
 * @author Paweł Brzeziński <pawel.brzezinski@smartint.pl>
 */
class FileResolverTest extends TestCase
{
    /** @var ObjectProphecy|FilesystemProviderInterface */
    private $fspMock;

    /** @var ObjectProphecy|PathCleanupInterface */
    private $pcMock;

    /** @var ObjectProphecy|LoggerInterface */
    private $loggerMock;

    protected function setUp()
    {
        $this->fspMock = $this->prophesize(FilesystemProviderInterface::class);
        $this->pcMock = $this->prophesize(PathCleanupInterface::class);
        $this->loggerMock = $this->prophesize(LoggerInterface::class);
    }

    protected function tearDown()
    {
        $this->fspMock = null;
        $this->pcMock = null;
        $this->loggerMock = null;
    }

    public function constructDataProvider()
    {
        $fsp = $this->prophesize(FilesystemProviderInterface::class)->reveal();
        $pc = $this->prophesize(PathCleanupInterface::class)->reveal();
        $logger = $this->prophesize(LoggerInterface::class)->reveal();
        $nullLogger = new NullLogger();

        return [
            'default logger parameter' => [$fsp, $pc, $nullLogger, $fsp, $pc, null],
            'custom logger parameter' => [$fsp, $pc, $logger, $fsp, $pc, $logger],
        ];
    }

    /**
     * @dataProvider constructDataProvider
     *
     * @param $expectedFsp
     * @param $expectedPc
     * @param $expectedLogger
     * @param $fsp
     * @param $pc
     * @param null|LoggerInterface $logger
     *
     * @throws
     */
    public function testConstruct($expectedFsp, $expectedPc, $expectedLogger, $fsp, $pc, $logger = null)
    {
        // Given
        $resolverUnderTest = null === $logger ? new FileResolver($fsp, $pc) : new FileResolver($fsp, $pc, $logger);

        // When
        $actualFsp = Reflection::getPropertyValue($resolverUnderTest, 'filesystemProvider');
        $actualLogger = Reflection::getPropertyValue($resolverUnderTest, 'logger');

        // Then
        $this->assertSame($expectedFsp, $actualFsp);

        if (null === $logger) {
            $this->assertInstanceOf(NullLogger::class, $actualLogger);
        } else {
            $this->assertSame($expectedLogger, $actualLogger);
        }
    }

    public function resolveFilePathDataProvider()
    {
        return [
            ['/foo/bar/file.jpeg', '/foo/bar', 'file.jpeg'],
            ['/foo/bar/file.jpeg', '/foo/bar/', '/file.jpeg'],
        ];
    }

    /**
     * @dataProvider resolveFilePathDataProvider
     *
     * @param $expected
     * @param $folder
     * @param $fileName
     */
    public function testResolveFilePath($expected, $folder, $fileName)
    {
        // When
        $actual = $this->buildResolver()->resolveFilePath($folder, $fileName);

        // Then
        $this->assertSame($expected, $actual);
    }

    public function resolveFileNameDataProvider()
    {
        return [
            'filename without extension' => ['example',  'example'],
            'filename with extension' => ['foo.jpg', 'foo.jpg'],
        ];
    }

    /**
     * @dataProvider resolveFileNameDataProvider
     *
     * @param string $expected
     * @param string $expectedNormalized
     * @param string $toNormalize
     * @param string $fileName
     */
    public function testResolveFileName($expected, $fileName)
    {
        // When
        $actual = $this->buildResolver()->resolveFileName($fileName);

        // Then
        $this->assertSame($expected, $actual);
    }

    public function resolveUniqueFileNameDataProvider()
    {
        return [
            ['file.jpeg', 0, '/foo/bar', 'file.jpeg', 'file-%counter%.jpeg'],
            ['file-1.jpeg', 1, '/foo/bar', 'file.jpeg', 'file-%counter%.jpeg'],
            ['file-7.jpeg', 7, '/foo/bar', 'file.jpeg', 'file-%counter%.jpeg'],
            ['file-7', 7, '/foo/bar', 'file', 'file-%counter%'],
        ];
    }

    /**
     * @dataProvider resolveUniqueFileNameDataProvider
     *
     * @param string $expectedFileName
     * @param int $expectedCount
     * @param $folder
     * @param $fileName
     * @param $fileNamePattern
     */
    public function testResolveUniqueFileName($expectedFileName, $expectedCount, $folder, $fileName, $fileNamePattern)
    {
        // Given

        // Mock FilesystemProviderInterface::has()
        for ($i = 0; $i <= $expectedCount; $i ++) {
            $tempFileName = 0 === $i ? $fileName : str_replace('%counter%', $i, $fileNamePattern);
            $tempFilePath = $folder.'/'.$tempFileName;
            $existsResult = $expectedCount !== $i;

            $this->loggerMock
                ->debug('Check file name uniqueness: '.$tempFilePath, [
                    'folder' => $folder,
                    'fileName' => $tempFileName,
                    'counter' => $i,
                ])
                ->shouldBeCalledTimes(1)
            ;

            $this->fspMock->exists($tempFilePath)->shouldBeCalledTimes(1)->willReturn($existsResult);
        }
        // End

        // When
        $actual = $this->buildResolver()->resolveUniqueFileName($folder, $fileName, 0);

        // Then
        $this->assertSame($expectedFileName, $actual);
    }

    public function resolveFormatFilePathDataProvider()
    {
        return [
            ['/format-1/foo/bar/1-file.jpeg', 1, '/foo/bar', 'format-1', 'file.jpeg'],
            ['/format-2/foo/bar/20-file.jpeg', 20, '/foo/bar/', 'format-2', 'file.jpeg'],
            ['/format-3/foo/bar/30-file.jpeg', 30, 'foo/bar/', '/format-3', 'file.jpeg'],
        ];
    }

    /**
     * @dataProvider resolveFormatFilePathDataProvider
     *
     * @param $expected
     * @param $id
     * @param $folder
     * @param $format
     * @param $fileName
     */
    public function testResolveFormatFilePath($expected, $id, $folder, $format, $fileName)
    {
        // When
        $actual = $this->buildResolver()->resolveFormatFilePath($id, $folder, $format, $fileName);

        // Then
        $this->assertSame($expected, $actual);
    }

    /**
     * @return FileResolver
     */
    private function buildResolver()
    {
        return new FileResolver($this->fspMock->reveal(), $this->pcMock->reveal(), $this->loggerMock->reveal());
    }
}
