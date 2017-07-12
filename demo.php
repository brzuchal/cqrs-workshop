<?php declare(strict_types = 1);
use App\Command\CreateAccount;
use App\Domain\Account;
use App\Domain\AccountWasCreated;
use App\Infrastructure\AccountRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDOException;
use Doctrine\DBAL\DriverManager;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventSourcing\Aggregate\AggregateRepository;
use Prooph\EventSourcing\Aggregate\AggregateType;
use Prooph\EventSourcing\EventStoreIntegration\AggregateTranslator;
use Prooph\EventStore\Exception\StreamExistsAlready;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlAggregateStreamStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlSimpleStreamStrategy;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Prooph\ServiceBus\CommandBus;
use Prooph\ServiceBus\EventBus;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/vendor/autoload.php';

$output = new ConsoleOutput();


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

$eventBus = new EventBus();
$eventStore = new MySqlEventStore(
    new FQCNMessageFactory(),
    $conn->getWrappedConnection(),
//    new MySqlAggregateStreamStrategy()
    new MySqlSimpleStreamStrategy()
);

$streamName = new StreamName('event_stream');
$singleStream = new Stream($streamName, new ArrayIterator());

// run only once
try {
    $eventStore->create($singleStream);
} catch (StreamExistsAlready $exception) {}

$eventRouter = new EventRouter();
$eventRouter->attachToMessageBus($eventBus);

$commandBus = new CommandBus();

$commandRouter = new CommandRouter();
$commandRouter->attachToMessageBus($commandBus);

// eof bootstrap

$aggregateRepository = new AggregateRepository(
    $eventStore,
    AggregateType::fromAggregateRootClass(Account::class),
    new AggregateTranslator()
);
$accountRepository = new AccountRepository($aggregateRepository);


$commandRouter->route(CreateAccount::class)->to(function (CreateAccount $command) use ($accountRepository) {
    $account = Account::create(Uuid::fromString($command->id()), $command->currency());
    $accountRepository->save($account);
});
$eventRouter->route(AccountWasCreated::class)->to(function (AccountWasCreated $event) use ($output) {
    $output->writeln("<info>AccountWasCreated</info>: {{$event->aggregateId()}} with currency in {$event->currency()}");
});


$id = Uuid::uuid4();
$createAccountCommand = new CreateAccount($id, 'PLN');
$commandBus->dispatch($createAccountCommand);

$account = $accountRepository->get($id);
dump($account);