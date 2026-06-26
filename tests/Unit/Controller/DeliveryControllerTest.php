<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\DeliveryController;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for DeliveryController — list, get, create, patch, and setStatus.
 *
 * DeliveryEtagTest.php already covers the ETag computation algorithm.
 * These tests focus on the action method paths: error branches and happy paths.
 */
class DeliveryControllerTest extends TestCase
{
    private const ORDER_ID    = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const DELIVERY_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb01';
    private const VERSION_ID  = 'cccccccccccccccccccccccccccccc01';
    private const COUNTRY_ID  = 'dddddddddddddddddddddddddddddd01';
    private const SALUTATION_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeee01';
    private const INITIAL_STATE_ID = 'ffffffffffffffffffffffffffffffff';

    // ── helpers ───────────────────────────────────────────────────────────────

    private function context(): Context
    {
        return Context::createDefaultContext();
    }

    /**
     * Build a controller with the given repositories and services.
     * Accepts partial overrides — each parameter defaults to a neutral stub.
     */
    private function makeController(
        ?EntityRepository $orderRepo = null,
        ?EntityRepository $deliveryRepo = null,
        ?EntityRepository $addressRepo = null,
        ?StateMachineService $stateMachine = null,
        ?InitialStateIdLoader $initialStateLoader = null,
        ?EntityRepository $countryRepo = null,
        ?EntityRepository $salutationRepo = null,
    ): DeliveryController {
        return new DeliveryController(
            $orderRepo           ?? $this->makeOrderRepo(null),
            $deliveryRepo        ?? $this->makeDeliveryRepo(null),
            $addressRepo         ?? $this->createStub(EntityRepository::class),
            $stateMachine        ?? $this->createStub(StateMachineService::class),
            $initialStateLoader  ?? $this->makeInitialStateLoader(),
            $countryRepo         ?? $this->makeCountryRepo(null),
            $salutationRepo      ?? $this->makeSalutationRepo(null),
            new EtagComparator(),
        );
    }

    /**
     * Build a mock order repo that returns the given order on every search().
     * Null means "order not found".
     */
    private function makeOrderRepo(?OrderEntity $order): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($order);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    /**
     * Build a mock delivery repo that always returns the same delivery on search().
     * Null means "delivery not found".
     */
    private function makeDeliveryRepo(?OrderDeliveryEntity $delivery): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($delivery);
        $result->method('getElements')->willReturn($delivery !== null ? [$delivery] : []);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    /**
     * Build a delivery repo that returns different results on sequential search() calls.
     * Used for patch/setStatus which call findDelivery() twice (before + after update).
     */
    private function makeDeliveryRepoSequential(
        ?OrderDeliveryEntity $first,
        ?OrderDeliveryEntity $second,
    ): EntityRepository {
        $firstResult = $this->createStub(EntitySearchResult::class);
        $firstResult->method('first')->willReturn($first);

        $secondResult = $this->createStub(EntitySearchResult::class);
        $secondResult->method('first')->willReturn($second);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnOnConsecutiveCalls($firstResult, $secondResult);

        return $repo;
    }

    /**
     * Build a delivery repo for create() tests.
     *
     * create() calls orderDeliveryRepository->create() (write, not search) and then
     * calls findDelivery() which calls search(). So there is exactly one search()
     * call on the delivery repo — return the created delivery directly.
     */
    private function makeDeliveryRepoForCreate(?OrderDeliveryEntity $createdDelivery): EntityRepository
    {
        $createdResult = $this->createStub(EntitySearchResult::class);
        $createdResult->method('first')->willReturn($createdDelivery);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($createdResult);

        return $repo;
    }

    private function makeCountryRepo(?CountryEntity $country): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($country);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    private function makeSalutationRepo(?SalutationEntity $salutation): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($salutation);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    private function makeInitialStateLoader(string $stateId = self::INITIAL_STATE_ID): InitialStateIdLoader
    {
        $loader = $this->createMock(InitialStateIdLoader::class);
        $loader->method('get')->willReturn($stateId);

        return $loader;
    }

    /**
     * Build a minimal OrderEntity stub sufficient for DeliveryController usage.
     */
    private function makeOrderEntity(string $id = self::ORDER_ID): OrderEntity
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($id);
        $order->method('getVersionId')->willReturn(self::VERSION_ID);
        $order->method('getBillingAddressId')->willReturn('billing-addr-id-01');
        $order->method('getDeliveries')->willReturn(null);

        return $order;
    }

    /**
     * Build a minimal OrderDeliveryEntity stub that satisfies mapDelivery().
     * All fields that mapDelivery() accesses are stubbed.
     */
    private function makeDeliveryEntity(
        string $id = self::DELIVERY_ID,
        string $orderId = self::ORDER_ID,
        string $status = 'open',
    ): OrderDeliveryEntity {
        $state = $this->createStub(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn($status);

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getId')->willReturn($id);
        $delivery->method('getUniqueIdentifier')->willReturn($id);
        $delivery->method('getOrderId')->willReturn($orderId);
        $delivery->method('getVersionId')->willReturn(self::VERSION_ID);
        $delivery->method('getUpdatedAt')->willReturn(new \DateTime('2024-01-01T12:00:00+00:00'));
        $delivery->method('getCreatedAt')->willReturn(new \DateTime('2024-01-01T10:00:00+00:00'));
        $delivery->method('getStateMachineState')->willReturn($state);
        $delivery->method('getTrackingCodes')->willReturn([]);
        $delivery->method('getShippingMethod')->willReturn(null);
        $delivery->method('getShippingOrderAddress')->willReturn(null);

        return $delivery;
    }

    /**
     * Build an OrderDeliveryEntity with a full shipping address (exercises mapAddress).
     * Built from scratch — not via makeDeliveryEntity() — to avoid mock re-configuration.
     */
    private function makeDeliveryEntityWithAddress(string $countryIso = 'DE'): OrderDeliveryEntity
    {
        $country = $this->createStub(CountryEntity::class);
        $country->method('getIso')->willReturn($countryIso);

        $address = $this->createMock(OrderAddressEntity::class);
        $address->method('getFirstName')->willReturn('Jane');
        $address->method('getLastName')->willReturn('Doe');
        $address->method('getStreet')->willReturn('Main St 1');
        $address->method('getZipcode')->willReturn('12345');
        $address->method('getCity')->willReturn('Berlin');
        $address->method('getCountry')->willReturn($country);

        $state = $this->createStub(StateMachineStateEntity::class);
        $state->method('getTechnicalName')->willReturn('open');

        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getId')->willReturn(self::DELIVERY_ID);
        $delivery->method('getUniqueIdentifier')->willReturn(self::DELIVERY_ID);
        $delivery->method('getOrderId')->willReturn(self::ORDER_ID);
        $delivery->method('getVersionId')->willReturn(self::VERSION_ID);
        $delivery->method('getUpdatedAt')->willReturn(new \DateTime('2024-01-01T12:00:00+00:00'));
        $delivery->method('getCreatedAt')->willReturn(new \DateTime('2024-01-01T10:00:00+00:00'));
        $delivery->method('getStateMachineState')->willReturn($state);
        $delivery->method('getTrackingCodes')->willReturn([]);
        $delivery->method('getShippingMethod')->willReturn(null);
        $delivery->method('getShippingOrderAddress')->willReturn($address);

        return $delivery;
    }

    /**
     * Build a country entity stub with the given ID and ISO code.
     */
    private function makeCountryEntity(string $id = self::COUNTRY_ID, string $iso = 'DE'): CountryEntity
    {
        $country = $this->createMock(CountryEntity::class);
        $country->method('getId')->willReturn($id);
        $country->method('getIso')->willReturn($iso);

        return $country;
    }

    private function makeSalutationEntity(string $id = self::SALUTATION_ID): SalutationEntity
    {
        $salutation = $this->createMock(SalutationEntity::class);
        $salutation->method('getId')->willReturn($id);

        return $salutation;
    }

    private function makeRequest(
        string $method = 'GET',
        string $body = '',
        ?string $ifMatch = null,
    ): Request {
        $request = new Request([], [], [], [], [], [], $body ?: null);
        $request->setMethod($method);
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }

        return $request;
    }

    /**
     * Compute the ETag that DeliveryController::deliveryEtagFor() would produce
     * for a given delivery entity, using the same algorithm.
     */
    private function etagFor(OrderDeliveryEntity $delivery): string
    {
        $material = $delivery->getId()
            . '|' . ($delivery->getVersionId() ?? '')
            . '|' . ($delivery->getUpdatedAt()?->format('U.u') ?? $delivery->getCreatedAt()?->format('U.u') ?? '');

        return 'W/"' . sha1($material) . '"';
    }

    // ── list() ────────────────────────────────────────────────────────────────

    public function testListOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo(null),
        );

        $this->expectException(OrderNotFoundException::class);
        $controller->list(self::ORDER_ID, $this->context());
    }

    public function testListOrderFoundNoDeliveriesReturnsEmptyItems(): void
    {
        $order = $this->makeOrderEntity();
        $deliveryRepo = $this->makeDeliveryRepo(null); // search returns nothing
        // list() calls search() on deliveryRepo and gets an empty elements array

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $deliveryRepo,
        );

        $response = $controller->list(self::ORDER_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('items', $body);
        self::assertSame([], $body['items']);
    }

    public function testListOrderFoundWithDeliveriesReturnsMappedArray(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getElements')->willReturn([$delivery]);

        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->method('search')->willReturn($result);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $deliveryRepo,
        );

        $response = $controller->list(self::ORDER_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('items', $body);
        self::assertCount(1, $body['items']);
        self::assertSame(self::DELIVERY_ID, $body['items'][0]['id']);
    }

    public function testListResponseBodyItemContainsExpectedFields(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getElements')->willReturn([$delivery]);

        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->method('search')->willReturn($result);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $deliveryRepo,
        );

        $response = $controller->list(self::ORDER_ID, $this->context());

        $body = json_decode($response->getContent(), true);
        $item = $body['items'][0];
        self::assertArrayHasKey('id', $item);
        self::assertArrayHasKey('orderId', $item);
        self::assertArrayHasKey('status', $item);
        self::assertArrayHasKey('trackingCodes', $item);
    }

    // ── get() ─────────────────────────────────────────────────────────────────

    public function testGetOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo(null),
        );

        $this->expectException(OrderNotFoundException::class);
        $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());
    }

    public function testGetDeliveryNotFoundOnOrderThrows(): void
    {
        $order = $this->makeOrderEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo(null),
        );

        $this->expectException(OrderNotFoundException::class);
        $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());
    }

    public function testGetDeliveryFoundReturns200(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $response = $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetDeliveryFoundResponseHasEtagHeader(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $response = $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());

        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testGetDeliveryFoundResponseBodyContainsDeliveryId(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $response = $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(self::DELIVERY_ID, $body['id']);
    }

    public function testGetDeliveryWithAddressMapsCountryCode(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntityWithAddress('FR');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $response = $controller->get(self::ORDER_ID, self::DELIVERY_ID, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame('FR', $body['shippingAddress']['countryCode']);
    }

    // ── create() ──────────────────────────────────────────────────────────────

    public function testCreateOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo(null),
        );

        $request = $this->makeRequest('POST', '{}');

        $this->expectException(OrderNotFoundException::class);
        $controller->create(self::ORDER_ID, $request, $this->context());
    }

    public function testCreateUnknownCountryCodeThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->createStub(EntityRepository::class),
            addressRepo: $this->createStub(EntityRepository::class),
            initialStateLoader: $this->makeInitialStateLoader(),
            countryRepo: $this->makeCountryRepo(null), // country not found
            salutationRepo: $this->makeSalutationRepo($this->makeSalutationEntity()),
        );

        $body = json_encode([
            'shippingAddress' => [
                'firstName'   => 'Jane',
                'lastName'    => 'Doe',
                'street'      => 'Main St 1',
                'zipcode'     => '12345',
                'city'        => 'Berlin',
                'countryCode' => 'ZZ', // unknown ISO code
            ],
        ]);

        $request = $this->makeRequest('POST', $body);

        $this->expectException(ValidationException::class);
        $controller->create(self::ORDER_ID, $request, $this->context());
    }

    public function testCreateHappyPathNoShippingAddressReturns201(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoForCreate($delivery),
            addressRepo: $this->createStub(EntityRepository::class),
            initialStateLoader: $this->makeInitialStateLoader(),
            countryRepo: $this->makeCountryRepo(null),
            salutationRepo: $this->makeSalutationRepo(null),
        );

        $request = $this->makeRequest('POST', '{}');

        $response = $controller->create(self::ORDER_ID, $request, $this->context());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateHappyPathResponseHasEtagHeader(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoForCreate($delivery),
            addressRepo: $this->createStub(EntityRepository::class),
            initialStateLoader: $this->makeInitialStateLoader(),
            countryRepo: $this->makeCountryRepo(null),
            salutationRepo: $this->makeSalutationRepo(null),
        );

        $request = $this->makeRequest('POST', '{}');

        $response = $controller->create(self::ORDER_ID, $request, $this->context());

        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testCreateHappyPathWithShippingAddressResolvesCountryAndReturns201(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $country = $this->makeCountryEntity(iso: 'DE');
        $salutation = $this->makeSalutationEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoForCreate($delivery),
            addressRepo: $this->createStub(EntityRepository::class),
            initialStateLoader: $this->makeInitialStateLoader(),
            countryRepo: $this->makeCountryRepo($country),
            salutationRepo: $this->makeSalutationRepo($salutation),
        );

        $body = json_encode([
            'shippingAddress' => [
                'firstName'   => 'Jane',
                'lastName'    => 'Doe',
                'street'      => 'Main St 1',
                'zipcode'     => '12345',
                'city'        => 'Berlin',
                'countryCode' => 'DE',
            ],
        ]);

        $request = $this->makeRequest('POST', $body);

        $response = $controller->create(self::ORDER_ID, $request, $this->context());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testCreateHappyPathResponseBodyContainsDeliveryId(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoForCreate($delivery),
            addressRepo: $this->createStub(EntityRepository::class),
            initialStateLoader: $this->makeInitialStateLoader(),
            countryRepo: $this->makeCountryRepo(null),
            salutationRepo: $this->makeSalutationRepo(null),
        );

        $request = $this->makeRequest('POST', '{}');

        $response = $controller->create(self::ORDER_ID, $request, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(self::DELIVERY_ID, $body['id']);
    }

    // ── patch() ───────────────────────────────────────────────────────────────

    public function testPatchOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo(null),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["ABC"]}', ifMatch: '*');

        $this->expectException(OrderNotFoundException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchDeliveryNotFoundThrows(): void
    {
        $order = $this->makeOrderEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo(null),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["ABC"]}', ifMatch: '*');

        $this->expectException(OrderNotFoundException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchMissingIfMatchHeaderThrows(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["ABC"]}'); // no If-Match

        $this->expectException(PreconditionRequiredException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $currentEtag = $this->etagFor($delivery);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["ABC"]}', ifMatch: 'W/"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchNoMutableFieldsThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        // Body contains no mutable fields (trackingCodes or shippingMethodId)
        $request = $this->makeRequest('PATCH', '{"unknownField":"value"}', ifMatch: $etag);

        $this->expectException(ValidationException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchEmptyBodyThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PATCH', '{}', ifMatch: $etag);

        $this->expectException(ValidationException::class);
        $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testPatchHappyPathReturns200(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);

        // patch() calls findDelivery() twice: once to get the ETag, once after update
        $updatedDelivery = $this->makeDeliveryEntity(status: 'shipped');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["TRACK123"]}', ifMatch: $etag);

        $response = $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPatchHappyPathResponseHasEtagHeader(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);
        $updatedDelivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["TRACK456"]}', ifMatch: $etag);

        $response = $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testPatchWildcardIfMatchIsAccepted(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $updatedDelivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
        );

        $request = $this->makeRequest('PATCH', '{"trackingCodes":["WILD"]}', ifMatch: '*');

        $response = $controller->patch(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    // ── setStatus() ───────────────────────────────────────────────────────────

    public function testSetStatusOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo(null),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: '*');

        $this->expectException(OrderNotFoundException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusDeliveryNotFoundThrows(): void
    {
        $order = $this->makeOrderEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo(null),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: '*');

        $this->expectException(OrderNotFoundException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusMissingIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}'); // no If-Match

        $this->expectException(PreconditionRequiredException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: 'W/"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusMissingStatusFieldThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PUT', '{}', ifMatch: $etag); // no status field

        $this->expectException(ValidationException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusEmptyStatusStringThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepo($delivery),
        );

        $request = $this->makeRequest('PUT', '{"status":""}', ifMatch: $etag);

        $this->expectException(ValidationException::class);
        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusHappyPathCallsTransitionDelivery(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);
        $updatedDelivery = $this->makeDeliveryEntity(status: 'shipped');

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::once())
            ->method('transitionDelivery')
            ->with(self::DELIVERY_ID, 'shipped', self::anything());

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
            stateMachine: $stateMachine,
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: $etag);

        $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());
    }

    public function testSetStatusHappyPathReturns200(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);
        $updatedDelivery = $this->makeDeliveryEntity(status: 'shipped');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
            stateMachine: $this->createStub(StateMachineService::class),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: $etag);

        $response = $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testSetStatusHappyPathResponseHasEtagHeader(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity();
        $etag = $this->etagFor($delivery);
        $updatedDelivery = $this->makeDeliveryEntity(status: 'shipped');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
            stateMachine: $this->createStub(StateMachineService::class),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: $etag);

        $response = $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testSetStatusHappyPathResponseBodyContainsUpdatedStatus(): void
    {
        $order = $this->makeOrderEntity();
        $delivery = $this->makeDeliveryEntity(status: 'open');
        $etag = $this->etagFor($delivery);
        $updatedDelivery = $this->makeDeliveryEntity(status: 'shipped');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo($order),
            deliveryRepo: $this->makeDeliveryRepoSequential($delivery, $updatedDelivery),
            stateMachine: $this->createStub(StateMachineService::class),
        );

        $request = $this->makeRequest('PUT', '{"status":"shipped"}', ifMatch: $etag);

        $response = $controller->setStatus(self::ORDER_ID, self::DELIVERY_ID, $request, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame('shipped', $body['status']);
    }
}
