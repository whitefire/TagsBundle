<?php

namespace Netgen\TagsBundle\Tests\Core\Search\Legacy\Content;

use eZ\Publish\Core\Search\Legacy\Content;
use eZ\Publish\SPI\Persistence\Content as ContentObject;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\SPI\Persistence\Content\VersionInfo;
use eZ\Publish\SPI\Persistence\Content\ContentInfo;
use eZ\Publish\Core\Persistence\Legacy\Tests\Content\LanguageAwareTestCase;
use eZ\Publish\Core\Persistence\Legacy\Content\FieldValue\ConverterRegistry;
use eZ\Publish\Core\Search\Legacy\Content\Common\Gateway\CriterionHandler;
use Netgen\TagsBundle\API\Repository\Values\Content\Query\Criterion;
use Netgen\TagsBundle\Core\Search\Legacy\Content\Common\Gateway\CriterionHandler\Tags\TagId as TagIdCriterionHandler;
use Netgen\TagsBundle\Core\Search\Legacy\Content\Common\Gateway\CriterionHandler\Tags\TagKeyword as TagKeywordCriterionHandler;

/**
 * Test case for legacy content search handler with Tags criteria.
 *
 * @todo Test with criterion target
 * @todo Test TagKeyword criterion with languages/translations
 */
class HandlerContentTest extends LanguageAwareTestCase
{
    protected static $setUp = false;

    /**
     * Field registry mock.
     *
     * @var \eZ\Publish\Core\Persistence\Legacy\Content\FieldValue\ConverterRegistry
     */
    protected $fieldRegistry;

    /**
     * Only set up once for these read only tests on a large fixture.
     *
     * Skipping the reset-up, since setting up for these tests takes quite some
     * time, which is not required to spent, since we are only reading from the
     * database anyways.
     */
    public function setUp()
    {
        if (!self::$setUp) {
            parent::setUp();
            $this->insertDatabaseFixture(__DIR__ . '/../../../../../vendor/ezsystems/ezpublish-kernel/eZ/Publish/Core/Search/Legacy/Tests/_fixtures/full_dump.php');
            self::$setUp = $this->handler;

            $handler = $this->getDatabaseHandler();

            $schema = __DIR__ . '/../../../../_fixtures/schema/schema.' . $this->db . '.sql';

            $queries = array_filter(preg_split('(;\\s*$)m', file_get_contents($schema)));
            foreach ($queries as $query) {
                $handler->exec($query);
            }

            $this->insertDatabaseFixture(__DIR__ . '/../../../../_fixtures/tags_tree.php');
        } else {
            $this->handler = self::$setUp;
        }

        $this->fieldRegistry = new ConverterRegistry();
    }

    /**
     * Assert search results.
     *
     * @param int[] $expectedIds
     * @param \eZ\Publish\API\Repository\Values\Content\Search\SearchResult $searchResult
     */
    protected function assertSearchResults($expectedIds, $searchResult)
    {
        $result = array_map(
            function ($hit) {
                return $hit->valueObject->id;
            },
            $searchResult->searchHits
        );

        sort($result);

        $this->assertEquals($expectedIds, $result);
    }

    /**
     * Returns the content search handler to test.
     *
     * This method returns a fully functional search handler to perform tests
     * on.
     *
     * @return \eZ\Publish\Core\Search\Legacy\Content\Handler
     */
    protected function getContentSearchHandler()
    {
        return new Content\Handler(
            new Content\Gateway\DoctrineDatabase(
                $this->getDatabaseHandler(),
                new Content\Common\Gateway\CriteriaConverter(
                    array(
                        new TagIdCriterionHandler(
                            $this->getDatabaseHandler()
                        ),
                        new TagKeywordCriterionHandler(
                            $this->getDatabaseHandler()
                        ),
                        new CriterionHandler\ContentId(
                            $this->getDatabaseHandler()
                        ),
                        new CriterionHandler\LogicalAnd(
                            $this->getDatabaseHandler()
                        ),
                        new CriterionHandler\MatchAll(
                            $this->getDatabaseHandler()
                        ),
                    )
                ),
                new Content\Common\Gateway\SortClauseConverter(
                    array(
                        new Content\Common\Gateway\SortClauseHandler\ContentId($this->getDatabaseHandler()),
                    )
                ),
                $this->getLanguageHandler()
            ),
            $this->getMock('eZ\\Publish\\Core\\Search\\Legacy\\Content\\Location\\Gateway'),
            $this->getContentMapperMock(),
            $this->getMock('eZ\\Publish\\Core\\Persistence\\Legacy\\Content\\Location\\Mapper'),
            $this->getLanguageHandler()
        );
    }

    /**
     * Returns a content mapper mock.
     *
     * @return \eZ\Publish\Core\Persistence\Legacy\Content\Mapper
     */
    protected function getContentMapperMock()
    {
        $mapperMock = $this->getMock(
            'eZ\\Publish\\Core\\Persistence\\Legacy\\Content\\Mapper',
            array('extractContentFromRows'),
            array(
                $this->fieldRegistry,
                $this->getLanguageHandler(),
            )
        );
        $mapperMock->expects($this->any())
            ->method('extractContentFromRows')
            ->with($this->isType('array'))
            ->will(
                $this->returnCallback(
                    function ($rows) {
                        $contentObjs = array();
                        foreach ($rows as $row) {
                            $contentId = (int)$row['ezcontentobject_id'];
                            if (!isset($contentObjs[$contentId])) {
                                $contentObjs[$contentId] = new ContentObject();
                                $contentObjs[$contentId]->versionInfo = new VersionInfo();
                                $contentObjs[$contentId]->versionInfo->contentInfo = new ContentInfo();
                                $contentObjs[$contentId]->versionInfo->contentInfo->id = $contentId;
                            }
                        }

                        return array_values($contentObjs);
                    }
                )
            );

        return $mapperMock;
    }

    public function testTagIdFilter()
    {
        $this->assertSearchResults(
            array(57, 60),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Criterion\TagId(40),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagIdFilterIn()
    {
        $this->assertSearchResults(
            array(57, 60, 61),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Criterion\TagId(array(40, 41)),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagIdFilterWithLogicalAnd()
    {
        $this->assertSearchResults(
            array(57),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Query\Criterion\LogicalAnd(
                            array(
                                new Criterion\TagId(16),
                                new Criterion\TagId(40),
                            )
                        ),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagKeywordFilter()
    {
        $this->assertSearchResults(
            array(57, 60),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Criterion\TagKeyword(Query\Criterion\Operator::EQ, 'eztags'),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagKeywordFilterIn()
    {
        $this->assertSearchResults(
            array(57, 60, 61),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Criterion\TagKeyword(Query\Criterion\Operator::IN, array('eztags', 'cms')),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagKeywordFilterInWithLogicalAnd()
    {
        $this->assertSearchResults(
            array(57),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Query\Criterion\LogicalAnd(
                            array(
                                new Criterion\TagKeyword(Query\Criterion\Operator::EQ, 'mobile'),
                                new Criterion\TagKeyword(Query\Criterion\Operator::EQ, 'eztags'),
                            )
                        ),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }

    public function testTagKeywordFilterLike()
    {
        $this->assertSearchResults(
            array(57, 58, 59, 60),
            $this->getContentSearchHandler()->findContent(
                new Query(
                    array(
                        'filter' => new Criterion\TagKeyword(Query\Criterion\Operator::LIKE, '%e%'),
                        'limit' => 10,
                        'sortClauses' => array(new SortClause\ContentId()),
                    )
                )
            )
        );
    }
}
