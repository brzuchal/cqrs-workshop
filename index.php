<?php declare(strict_types = 1);

use App\Command\CreateAccount;
use Doctrine\DBAL\Connection;
use Prooph\ServiceBus\CommandBus;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/vendor/autoload.php';

$app = new \Silex\Application();
$app['debug'] = true;

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/bootstrap.php';

// app
$app->get('/accounts', function () use ($app) {
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
$app->get('/accounts/{id}', function ($id) use ($app) {
    /** @var Connection $dbConn */
    $dbConn = $app['db.conn'];
    $stmt = $dbConn->executeQuery('SELECT * FROM accounts WHERE id = :id', [
        'id' => $id,
    ]);
    $stmt->execute();
    return new JsonResponse($stmt->fetch());
});
// eof app

$app->run();
