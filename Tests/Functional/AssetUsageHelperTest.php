<?php
namespace PunktDe\Elastic\AssetUsageInNodes\Tests\Functional;


use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Command\NodeIndexCommandController;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Eel\Context;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Document;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\DocumentRepository;
use Neos\Media\Domain\Repository\ImageRepository;
use Neos\Utility\Files;
use PunktDe\Elastic\AssetUsageInNodes\Eel\AssetUsageHelper;
use PunktDe\Elastic\AssetUsageInNodes\Exception\AssetUsageExtractionException;

class AssetUsageHelperTest extends FunctionalTestCase
{
    /**
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @var NodeIndexCommandController
     */
    protected $nodeIndexCommandController;

    /**
     * @var Context
     */
    protected $context;

    /**
     * @var NodeInterface
     */
    protected $siteNode;

    /**
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @var AssetUsageHelper
     */
    protected $assetUsageHelper;

    /**
     * @var DocumentRepository
     */
    protected $documentRepository;

    /**
     * @var ImageRepository
     */
    protected $imageRepository;

    /**
     * @var ResourceRepository
     */
    protected $resourceRepository;

    /**
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @var Image
     */
    protected $testImageAsset;

    /**
     * @var Document
     */
    protected $testDocumentAsset;

    protected static $testablePersistenceEnabled = true;

    public function setUp(): void
    {
        parent::setUp();

        $this->resourceRepository = $this->objectManager->get(ResourceRepository::class);
        $this->documentRepository = $this->objectManager->get(DocumentRepository::class);
        $this->imageRepository = $this->objectManager->get(ImageRepository::class);
        $this->resourceManager = $this->objectManager->get(ResourceManager::class);

        $assetUsageProxy = $this->buildAccessibleProxy(AssetUsageHelper::class);
        $this->assetUsageHelper = new $assetUsageProxy();
        $this->inject($this->assetUsageHelper, 'persistenceManager', $this->persistenceManager);

        $this->setupTestAssets();
        $this->setupTestNodes();
    }

    /**
     * @return array
     */
    public function assetsInContentDataProvider(): array
    {
        return [
            'singleAsset' => [
                'content' => 'das <a href="asset://f8f075dc-7a43-40d6-b279-8052271eab28">Management<\/a>.',
                'expected' => ['f8f075dc-7a43-40d6-b279-8052271eab28']
            ],
            'multipleAssets' => [
                'content' => 'das <a href="asset://f8f075dc-7a43-40d6-b279-8052271eab28">Management<\/a> und die <a href="asset://f8f075dc-7a43-40d6-b279-8052271eab41">Kunden<\/a>',
                'expected' => ['f8f075dc-7a43-40d6-b279-8052271eab28', 'f8f075dc-7a43-40d6-b279-8052271eab41']
            ],
            'nodesAreNotIncludes' => [
                'content' => 'das <a href="asset://f8f075dc-7a43-40d6-b279-8052271eab28">Management<\/a>. Klicken sie <a href="node://00079b3b-d95e-4601-b15b-6df049c0a5bf">hier<\/a>',
                'expected' => ['f8f075dc-7a43-40d6-b279-8052271eab28']
            ],
        ];
    }

    /**
     * @test
     * @dataProvider assetsInContentDataProvider
     * @param string $content
     * @param array $expected
     */
    public function getAssetReferencesFromContent(string $content, array $expected): void
    {
        $actual = $this->assetUsageHelper->_call('getAssetReferencesFromContent', $content);
        self::assertEquals($expected, $actual);
    }

    /**
     * @test
     */
    public function getAssetReferencesFromObjects(): void
    {
        $input = [
            $this->testDocumentAsset,
            new \stdClass(),
            $this->testImageAsset
        ];

        $actual = $this->assetUsageHelper->_call('getAssetReferencesFromObjects', $input);

        $expected = [
            $this->persistenceManager->getIdentifierByObject($this->testDocumentAsset),
            $this->persistenceManager->getIdentifierByObject($this->testImageAsset)
        ];

        $this->assertEquals($expected, $actual);
    }

    /**
     * @test
     *
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     * @throws NodeException
     */
    public function extractReferencedAssets(): void
    {
        $assetContainingNode = $this->siteNode->getNode('main')->createNode('asset-node', $this->nodeTypeManager->getNodeType('PunktDe.Elastic.AssetUsageInNodes:AssetReferencingNode'));

        $assetContainingNode->setProperty('date', new \DateTime());
        $assetContainingNode->setProperty('text', 'das <a href="asset://f8f075dc-7a43-40d6-b279-8052271eab28">Management<\/a>.');
        $assetContainingNode->setProperty('image', $this->testImageAsset);
        $assetContainingNode->setProperty('asset', $this->testDocumentAsset);

        $assetReferences = $this->assetUsageHelper->extractReferencedAssets($assetContainingNode);

        self::assertCount(3, $assetReferences);

        $expectedAssetReferences = [
            'f8f075dc-7a43-40d6-b279-8052271eab28',
            $this->persistenceManager->getIdentifierByObject($this->testImageAsset),
            $this->persistenceManager->getIdentifierByObject($this->testDocumentAsset),
        ];

        self::assertEquals($expectedAssetReferences, $assetReferences);
    }

    /**
     * @test
     *
     * @throws NodeException
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function extractReferencesFromArrays(): void
    {
        $assetContainingNode = $this->siteNode->getNode('main')->createNode('asset-node', $this->nodeTypeManager->getNodeType('PunktDe.Elastic.AssetUsageInNodes:AssetReferencingNode'));

        $assetContainingNode->setProperty('assets', [$this->testImageAsset, $this->testDocumentAsset]);

        $assetReferences = $this->assetUsageHelper->extractReferencedAssets($assetContainingNode);

        self::assertCount(2, $assetReferences);

        $expectedAssetReferences = [
            $this->persistenceManager->getIdentifierByObject($this->testImageAsset),
            $this->persistenceManager->getIdentifierByObject($this->testDocumentAsset),
        ];

        self::assertEquals($expectedAssetReferences, $assetReferences);
    }

    /**
     * @test
     *
     * @throws NodeException
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     */
    public function sameAssetIsNotAddedTwice(): void
    {
        $assetContainingNode = $this->siteNode->getNode('main')->createNode('asset-node', $this->nodeTypeManager->getNodeType('PunktDe.Elastic.AssetUsageInNodes:AssetReferencingNode'));

        $assetContainingNode->setProperty('assets', [$this->testImageAsset]);
        $assetContainingNode->setProperty('image', $this->testImageAsset);
        $assetContainingNode->setProperty('text', sprintf('download <a href="asset://%s">Image<\/a>.', $this->persistenceManager->getIdentifierByObject($this->testImageAsset)));
        $assetReferences = $this->assetUsageHelper->extractReferencedAssets($assetContainingNode);

        self::assertCount(1, $assetReferences);

        $expectedAssetReferences = [
            $this->persistenceManager->getIdentifierByObject($this->testImageAsset)
        ];

        self::assertEquals($expectedAssetReferences, $assetReferences);
    }

    /**
     * @throws \Neos\Flow\ResourceManagement\Exception
     * @throws IllegalObjectTypeException
     */
    protected function setupTestAssets(): void
    {
        $testResourceImage = $this->resourceManager->importResource(Files::concatenatePaths([__DIR__, 'Fixtures/Logo.png']));
        $testResourceDocument = $this->resourceManager->importResource(Files::concatenatePaths([__DIR__, 'Fixtures/Document.docx']));

        $this->testDocumentAsset = new Document($testResourceDocument);
        $this->documentRepository->add($this->testDocumentAsset);

        $this->testImageAsset = new Image($testResourceImage);
        $this->imageRepository->add($this->testImageAsset);

        $this->persistenceManager->persistAll();
    }

    /**
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     * @throws IllegalObjectTypeException
     */
    protected function setupTestNodes(): void
    {
        $this->workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $liveWorkspace = new Workspace('live');
        $this->workspaceRepository->add($liveWorkspace);

        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
        $this->context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en_US']],
            'targetDimensions' => ['language' => 'en_US']
        ]);
        $rootNode = $this->context->getRootNode();

        $this->siteNode = $rootNode->createNode('welcome', $this->nodeTypeManager->getNodeType('Neos.NodeTypes:Page'));
        $this->siteNode->setProperty('title', 'welcome');
        $this->siteNode->createNode('main',  $this->nodeTypeManager->getNodeType('Neos.Neos:ContentCollection'));

        $this->nodeDataRepository = $this->objectManager->get(NodeDataRepository::class);
    }
}
