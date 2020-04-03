<?php
namespace PunktDe\Elastic\AssetUsageInNodes\Eel;

/*
 * This file is part of the PunktDe.Elastic.AssetUsageInNodes package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Log\PsrSystemLoggerInterface;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Model\ResourceBasedInterface;
use Neos\Neos\Service\LinkingService;
use PunktDe\Elastic\AssetUsageInNodes\Exception\AssetUsageExtractionException;

class AssetUsageHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var PsrSystemLoggerInterface
     */
    protected $logger;

    /**
     * @param NodeInterface $node
     * @return array
     * @throws NodeException
     */
    public function extractReferencedAssets(NodeInterface $node): array
    {
        $assetReferences = [];
        $propertyConfiguration = $node->getNodeType()->getProperties();

        foreach ($node->getPropertyNames() as $propertyName) {
            if(!isset($propertyConfiguration[$propertyName])) {
                continue;
            }

            $assetReferences = array_merge($assetReferences, $this->extractReferencedAssetFromProperty($node, $propertyName));
        }

        return array_unique($assetReferences);
    }

    /**
     * @param NodeInterface $node
     * @param string $propertyName
     * @return array
     * @throws NodeException
     */
    protected function extractReferencedAssetFromProperty(NodeInterface $node, string $propertyName): array
    {
        $propertyType = $node->getNodeType()->getPropertyType($propertyName);

        if ($propertyType === 'string') {
            $propertyValue = $node->getProperty($propertyName);

            if(gettype($propertyValue) === 'NULL') {
                return [];
            }

            if (gettype($propertyValue) === 'string') {
                return $this->getAssetReferencesFromContent($propertyValue);
            } else {
                $this->logger->warning(sprintf('The property named %s of node %s is configured as string, but contains %s. The property is ignored!', $propertyName, $node->getIdentifier(), gettype($propertyValue)));
            }
        }

        $singlePropertyType = $this->stripArrayFromPropertyType($propertyType);

        if (class_exists($singlePropertyType) || interface_exists($singlePropertyType)) {
            $propertyValue = $node->getProperty($propertyName);
            return $this->getAssetReferencesFromObjects(is_array($propertyValue) ? $propertyValue : [$propertyValue]);
        }

        /* All other types should not contain assets */
        return [];
    }

    /**
     * @param string $content
     * @return array
     */
    protected function getAssetReferencesFromContent(string $content): array
    {
        // needs at least contain "asset://" + 36 chars UUID
        if (strlen($content) < 44) {
            return [];
        }

        $assetReferences = [];

        preg_match_all(LinkingService::PATTERN_SUPPORTED_URIS, $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($match[1]) && $match[1] === 'asset') {
                $assetReferences[] = $match[2];
            }
        }

        return $assetReferences;
    }

    /**
     * @param array $objects
     * @return array
     */
    protected function getAssetReferencesFromObjects(array $objects): array
    {
        $assetReferences = [];

        foreach ($objects as $object) {
            if ($object instanceof ResourceBasedInterface) {
                $assetReferences[] = $this->persistenceManager->getIdentifierByObject($object);
            }
        }

        return $assetReferences;
    }

    /**
     * @param string $propertyType
     * @return string
     */
    protected function stripArrayFromPropertyType(string $propertyType): string
    {
        if (substr($propertyType, 0, 6) === 'array<') {
            $propertyType = substr($propertyType, 6, -1);
        }

        return $propertyType;
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
