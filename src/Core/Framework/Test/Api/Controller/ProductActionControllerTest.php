<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Test\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Pricing\Price;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\PlatformRequest;

class ProductActionControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    /**
     * @var EntityRepositoryInterface
     */
    private $productRepository;

    protected function setUp(): void
    {
        $this->productRepository = $this->getContainer()->get('product.repository');
    }

    public function testGenerateVariant(): void
    {
        $context = Context::createDefaultContext();
        $id = Uuid::uuid4()->getHex();
        $redId = Uuid::uuid4()->getHex();
        $blueId = Uuid::uuid4()->getHex();
        $colorId = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test',
            'tax' => ['name' => 'test', 'taxRate' => 15],
            'price' => ['gross' => 10, 'net' => 9, 'linked' => false],
            'manufacturer' => ['name' => 'test'],
            'configurators' => [
                [
                    'id' => $redId,
                    'price' => ['gross' => 50, 'net' => 25, 'linked' => false],
                    'option' => [
                        'id' => $redId,
                        'name' => 'red',
                        'group' => ['id' => $colorId, 'name' => $colorId],
                    ],
                ],
                [
                    'id' => $blueId,
                    'price' => ['gross' => 100, 'net' => 90, 'linked' => false],
                    'option' => [
                        'id' => $blueId,
                        'name' => 'blue',
                        'groupId' => $colorId,
                    ],
                ],
            ],
        ];

        $this->getClient()->request('POST', '/api/v' . PlatformRequest::API_VERSION . '/product', $data);

        static::assertSame(204, $this->getClient()->getResponse()->getStatusCode());

        $criteria = new Criteria([$id]);
        $criteria->addAssociation('product.configurators');
        /** @var ProductEntity $product */
        $product = $this->productRepository->search($criteria, $context)->get($id);

        $configurators = $product->getConfigurators();

        static::assertCount(2, $configurators);

        static::assertTrue($configurators->has($redId));
        static::assertTrue($configurators->has($blueId));

        $blue = $configurators->get($blueId);
        $red = $configurators->get($redId);

        static::assertEquals(new Price(25, 50, false), $red->getPrice());
        static::assertEquals(new Price(90, 100, false), $blue->getPrice());

        static::assertEquals('red', $red->getOption()->getName());
        static::assertEquals('blue', $blue->getOption()->getName());

        static::assertEquals($colorId, $red->getOption()->getGroupId());
        static::assertEquals($colorId, $blue->getOption()->getGroupId());

        $url = sprintf(
            '/api/v%s/_action/product/%s/generate-variant',
            PlatformRequest::API_VERSION,
            $product->getId()
        );

        $this->getClient()->request('POST', $url);
        static::assertSame(200, $this->getClient()->getResponse()->getStatusCode());

        $ids = $this->getClient()->getResponse()->getContent();
        static::assertNotEmpty($ids);

        $ids = json_decode($ids, true);
        static::assertArrayHasKey('data', $ids);
        static::assertCount(2, $ids['data']);

        $products = $this->productRepository->search(new Criteria($ids['data']), $context);

        foreach ($products as $product) {
            static::assertSame($id, $product->getParentId());
        }
    }
}
