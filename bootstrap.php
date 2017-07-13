<?php declare(strict_types = 1);

use App\Command\CreateAccount;
use App\Domain\Account;
use App\Domain\AccountWasCreated;
use App\Infrastructure\AccountRepository;
use Prooph\ServiceBus\Plugin\Router\CommandRouter;
use Prooph\ServiceBus\Plugin\Router\EventRouter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Request;

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
/** @var \Silex\Application $app */
$eventRouter->route(AccountWasCreated::class)->to(function (AccountWasCreated $event) use ($app) {
    $app['account_projection']->run(false);
});

$app->before(function (Request $request) {
    if (0 === strpos((string)$request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }
});
