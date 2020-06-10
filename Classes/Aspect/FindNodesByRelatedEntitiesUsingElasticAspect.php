<?php
declare(strict_types=1);

namespace PunktDe\Elastic\AssetUsageInNodes\Aspect;

/*
 * This file is part of the PunktDe.Elastic.AssetUsageInNodes package.
 *
 * This package is open source software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Driver\QueryInterface;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\ElasticSearchClient;
use Flowpack\ElasticSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * @Flow\Aspect
 */
class FindNodesByRelatedEntitiesUsingElasticAspect
{

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var ElasticSearchClient
     */
    protected $elasticSearchClient;

    /**
     * The Elasticsearch request, as it is being built up.
     *
     * @var QueryInterface
     * @Flow\Inject
     */
    protected $request;

    /**
     * @param JoinPointInterface $joinPoint
     * @Flow\Around("method(Neos\ContentRepository\Domain\Repository\NodeDataRepository->findNodesByPathPrefixAndRelatedEntities($pathPrefix, $relationMap))")
     *
     * @return array
     * @throws QueryBuildingException
     * @throws \Flowpack\ElasticSearch\Exception
     * @throws \Neos\Flow\Persistence\Exception\InvalidQueryException
     */
    public function findNodesByPathPrefixAndRelatedEntities(JoinPointInterface $joinPoint)
    {
        $pathPrefix = $joinPoint->getMethodArgument('pathPrefix');
        $relationMap = $joinPoint->getMethodArgument('relationMap');

        $relatedNodePaths = $this->getRelatedNodePaths($pathPrefix, $relationMap);

        $query = $this->nodeDataRepository->createQuery();
        $query->matching(
            $query->in('path', $relatedNodePaths)
        );

        return $query->execute()->toArray();
    }

    /**
     * @param string $pathPrefix
     * @param array $relationMap
     * @return array
     *
     * @throws QueryBuildingException
     * @throws \Flowpack\ElasticSearch\Exception
     */
    protected function getRelatedNodePaths(string $pathPrefix, array $relationMap): array
    {
        $this->request->queryFilter('bool', [
            'should' => [
                [
                    'term' => ['neos_parent_path ' => $pathPrefix]
                ],
                [
                    'term' => ['neos_path' => $pathPrefix]
                ]
            ]
        ]);

        $relatedAssetIdentifiers = [];

        foreach ($relationMap as $relatedObjectType => $relatedIdentifiers) {
            foreach ($relatedIdentifiers as $relatedIdentifier) {
                $relatedAssetIdentifiers[] = $relatedIdentifier;
            }
        }

        $this->request->queryFilter('terms', ['punktde_assetUsages' => $relatedAssetIdentifiers]);
        $this->request->setValueByPath('query.bool.filter.bool.must_not', []);
        $this->request->size(1000);

        $requestJson = $this->request->getRequestAsJson();

        $response = $this->elasticSearchClient->getIndex()->request('GET', '/_search', [], $requestJson)->getTreatedContent();

        $relatedNodePaths = [];

        foreach ($response['hits']['hits'] as $hit) {
            $relatedNodePaths[] = $hit['_source']['neos_path'];
        }

        return $relatedNodePaths;
    }
}
