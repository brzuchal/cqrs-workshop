<?php declare(strict_types = 1);
use App\Command\CreateAccount;
use App\Domain\Account;
use App\Domain\AccountWasCreated;
use App\Infrastructure\AccountRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Prooph\Common\Event\ProophActionEventEmitter;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventSourcing\Aggregate\AggregateRepository;
use Prooph\EventSourcing\Aggregate\AggregateType;
use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\ActionEventEmitterEventStore;
use Prooph\EventStore\Exception\StreamExistsAlready;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Prooph\EventStoreBusBridge\EventPublisher;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/vendor/autoload.php';

$app = new \Silex\Application();
$app['debug'] = true;

// services
$app['output'] = function () {
    return new ConsoleOutput();
};
$app['db.conn'] = function () {
    $config = new Configuration();
    $connectionParams = [
        'dbname' => \getenv('DB_NAME'),
        'user' => \getenv('DB_USER'),
        'password' => \getenv('DB_PASSWORD'),
        'host' => \getenv('DB_HOST'),
        'port' => \getenv('DB_PORT'),
        'driver' => 'pdo_mysql',
    ];
    $conn = DriverManager::getConnection($connectionParams, $config);

    // run only once
    try {
        $schemaFile = __DIR__ . '/vendor/prooph/pdo-event-store/scripts/mysql/01_event_streams_table.sql';
        $stmt = $conn->prepare(\file_get_contents($schemaFile));
        $stmt->execute();
    } catch (\PDOException | PDOException | \Throwable $exception) {}

    try {
        $schemaFile = __DIR__ . '/vendor/prooph/pdo-event-store/scripts/mysql/02_projections_table.sql';
        $stmt = $conn->prepare(\file_get_contents($schemaFile));
        $stmt->execute();
    } catch (\PDOException | PDOException | \Throwable $exception) {}

    return $conn;
};

$app['event_bus'] = function() {
    return new EventBus();
};
$app['event_store'] = function ($app) {
    /** @var Connection $dbConn */
    $dbConn = $app['db.conn'];
    $eventStore = new MySqlEventStore(
        new FQCNMessageFactory(),
        $dbConn->getWrappedConnection(),
        new MySqlSimpleStreamStrategy()
    );
    $streamName = new StreamName('event_stream');
    $singleStream = new Stream($streamName, new ArrayIterator());
    try {
        $eventStore->create($singleStream);
    } catch (StreamExistsAlready $exception) {

    } finally {
        $eventPublisher = new EventPublisher($app['event_bus']);
        // Important! Replacing MySqlEventStore with ActionEvent emiting one
        $eventStore = new ActionEventEmitterEventStore($eventStore, new ProophActionEventEmitter());
        $eventPublisher->attachToEventStore($eventStore);

        return $eventStore;
    }
};
$app['event_router'] = function ($app) {
    $eventRouter = new EventRouter();
    $eventRouter->attachToMessageBus($app['event_bus']);

    return $eventRouter;
};

$app['command_bus'] = function () {
    return new CommandBus();
};
$app['command_router'] = function ($app) {
    $commandRouter = new CommandRouter();
    $commandRouter->attachToMessageBus($app['command_bus']);

    return $commandRouter;
};

$app['projection_manager'] = function ($app) {
    /** @var Connection $dbConn */
    $dbConn = $app['db.conn'];
    return new MySqlProjectionManager(
        $app['event_store'],
        $dbConn->getWrappedConnection()
    );
};

$app['account_repository'] = function ($app) {
    $aggregateRepository = new AggregateRepository(
        $app['event_store'],
        AggregateType::fromAggregateRootClass(Account::class),
        new AggregateTranslator()
    );
    return new AccountRepository($aggregateRepository);
};
$app['account_projection'] = function ($app) {
    /** @var Connection $dbConn */
    $dbConn = $app['db.conn'];
    /** @var ProjectionManager $projectionManager */
    $projectionManager = $app['projection_manager'];
    $accountProjection = $projectionManager->createProjection('account_projection');
    $accountProjection->fromAll()->whenAny(function ($state, AggregateChanged $event) use ($dbConn) {
        $schemaManager = $dbConn->getSchemaManager();
        if (!$schemaManager->tablesExist('accounts')) {
            $accountsTable = new \Doctrine\DBAL\Schema\Table('accounts');
            $accountsTable->addColumn('id', 'string', ['length' => 36]);
            $accountsTable->addColumn('currency', 'string', ['length' => 4]);
            $accountsTable->addColumn('created_at', 'datetime');
            $schemaManager->createTable($accountsTable);
        }
        if ($event instanceof AccountWasCreated) {
            $dbConn->insert('accounts', [
                'id' => $event->aggregateId(),
                'currency' => $event->currency(),
                'created_at' => $event->createdAt()->format('Y-m-d H:i:s'),
            ]);
        }
    });
    return $accountProjection;
};
// eof services

// bootstrap
/** @var ConsoleOutput $output */
$output = $app['output'];
/** @var CommandRouter $commandRouter */
$commandRouter = $app['command_router'];
/** @var EventRouter $eventRouter */
$eventRouter = $app['event_router'];
/** @var AccountRepository $accountRepository */
$accountRepository = $app['account_repository'];

$commandRouter->route(CreateAccount::class)->to(function (CreateAccount $command) use ($accountRepository) {
    $account = Account::create(Uuid::fromString($command->id()), $command->currency());
    $accountRepository->save($account);
});
//$eventRouter->route(AccountWasCreated::class)->to(function (AccountWasCreated $event) use ($output) {
//    $output->writeln("<info>AccountWasCreated</info>: {{$event->aggregateId()}} with currency in {$event->currency()}");
//});
$eventRouter->route(AccountWasCreated::class)->to(function (AccountWasCreated $event) use ($app) {
    $app['account_projection']->run(false);
});

$app->before(function (Request $request) {
    if (0 === strpos((string)$request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});
// eof bootstrap

$app->get('/accounts', function (Request $request) use ($app) {
    /** @var Connection $dbConn */
    $dbConn = $app['db.conn'];
    $stmt = $dbConn->executeQuery('SELECT * FROM accounts');
    $stmt->execute();
    return new JsonResponse($stmt->fetchAll());
});
$app->put('/accounts/{id}', function ($id, Request $request) use ($app) {
    $currency = $request->request->get('currency');
    $createAccountCommand = new CreateAccount(
        Uuid::fromString($id),
        $currency
    );
    /** @var CommandBus $commandBus */
    $commandBus = $app['command_bus'];
    $commandBus->dispatch($createAccountCommand);

    return new Response(null, Response::HTTP_CREATED);
});
$app->run();
