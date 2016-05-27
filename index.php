<?php
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/config.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Debug\ErrorHandler;



$app = new Silex\Application();

// Please set to false in a production environment
$app['debug'] = true;

//configure database connection
$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
        'driver' => 'pdo_mysql',
        'host' => DB_HOST,
        'dbname' => DB_NAME,
        'user' => DB_USER,
        'password' => DB_PASSWORD,
        'charset' => DB_CHARSET,
    ),
    /*
        'db.options' => array(
            'driver'   => 'pdo_sqlite',
            'path'     => __DIR__.'/app.db',
        ),
    */
));

$app->register(new Silex\Provider\ValidatorServiceProvider());

$app->before(function (Request $request) use ($app) {
    if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent(), true);
        $request->request->replace(is_array($data) ? $data : array());
    }

    $token = $request->headers->get('Authorization');


    if (isset($token)) {
        $token = trim(str_replace('Bearer', '', $token));
        $app['token'] = $token;
        $sql = "SELECT * FROM tokens WHERE token = ?";
        $token = $app['db']->fetchAssoc($sql, array($token));
        if (!$token) return $app->json(array('status' => '401', 'message' => 'Unauthorized'), 401);
        $app['token_id'] = $token['id'];
    } else {
        return $app->json(array('status' => '401', 'message' => 'Unauthorized'), 401);
    }

});

$app->post('/api/accounts', function (Request $request) use ($app) {
    $user = array(
        'token_id' => $app['token_id'],
        'app_id' => md5(uniqid(rand(), true)),
        'email' => $request->request->get('email'),
        'password' => $request->request->get('password'),
        'name' => $request->request->get('name'),
        'address' => $request->request->get('address'),
        'city' => $request->request->get('city'),
        'state' => $request->request->get('state'),
        'zip' => $request->request->get('zip'),
        'country' => $request->request->get('country'),
    );

    $constraint_data = array(
        'token_id' => array(new Assert\NotBlank()),
        'app_id' => array(new Assert\NotBlank()),
        'email' => array(new Assert\NotBlank(), new Assert\Email(array(
            'message' => 'The email is not a valid.',
            'checkMX' => false,
        ))),
        'password' => array(new Assert\NotBlank()),
        'name' => array(new Assert\NotBlank()),
        'address' => array(new Assert\NotBlank()),
        'city' => array(new Assert\NotBlank()),
        'state' => array(new Assert\NotBlank()),
       // 'zip' => array(new Assert\NotBlank()),
        'country' => array(new Assert\NotBlank()),
    );

    //if (isset($user['country']) AND ($user['country'] == 'US')) $constraint_data['zip'] = array();

    if (isset($user['country']) AND ($user['country'] == 'US')) {
        $constraint_data['zip'] = array(new Assert\NotBlank(),
            new Assert\Regex(array(
                'pattern' => '/^\d{5}(?:[-\s]\d{4})?$/',
                'message' => 'Zip code is not valid. Must be NNNNN or NNNNN-NNNN'
            )));
    } else {
        $constraint_data['zip'] = array();
    }

    $constraint = new Assert\Collection($constraint_data);

    $errors = $app['validator']->validate($user, $constraint);

    if (count($errors) > 0) {
        $errs = array();
        foreach ($errors as $error) {
            $errs[$error->getPropertyPath()] = $error->getMessage();
        }

        return $app->json(array('status' => '404', 'message' => 'Not valid data', 'errors' => $errs), 404);
    }

    $sql = "SELECT * FROM users WHERE email = ? AND token_id = ?";
    $tuser = $app['db']->fetchAssoc($sql, array($user['email'], $app['token_id']));
    if ($tuser) return $app->json(array('status' => '409', 'message' => 'User with this email already exists'), 409);


    try {
        $app['db']->insert('users', $user);
        $app['db']->insert('users2', array('app_id' => $user['app_id'], 'date' => date('Y-m-d'), 'ip' => $request->getClientIp()));
    } catch(\Exception $e) {
        return $app->json(array('status' => '500', 'message' => 'Internal Server Error'), 500);
    }

    return $app->json(array('status' => '201', 'app_id' => $user['app_id']), 201);
});

$app->put('/api/accounts/{app_id}', function (Request $request, $app_id) use ($app) {
    $user = array(
        'schools' => $request->request->get('schools'),
        'location' => $request->request->get('location'),
        'size' => (int) $request->request->get('size', NULL),
        'age' => (int) $request->request->get('age', NULL),
    );


    $constraint = new Assert\Collection(array(
        'schools' => array(new Assert\NotBlank(), new Assert\Choice(array(
            'choices' => array('Y', 'N'),
            'message' => 'Not valid schools. "Y" or "N" available',
        ))),
        'location' => array(new Assert\NotBlank(), new Assert\Choice(array(
            'choices' => array('City', 'Suburbs', 'Country'),
            'message' => 'Not valid location. "City", "Suburbs", "Country" available',
        ))),
        'size' => array(new Assert\NotBlank(),
            new Assert\Type(array(
                'type'    => 'integer',
                'message' => 'Size must be integer.',
            )),
            new Assert\GreaterThan(array(
                'value' => 0,
            ))
        ),
        'age' => array(new Assert\NotBlank(),
            new Assert\Type(array(
                'type'    => 'integer',
                'message' => 'Age must be integer.',
            )),
            new Assert\GreaterThan(array(
                'value' => 0,
            ))
        ),
    ));
    $errors = $app['validator']->validate($user, $constraint);

    if (count($errors) > 0) {
        $errs = array();
        foreach ($errors as $error) {
            $errs[$error->getPropertyPath()] = $error->getMessage();
        }

        return $app->json(array('status' => '404', 'message' => 'Not valid data', 'errors' => $errs), 404);
    }


    $sql = "SELECT * FROM users WHERE app_id = ? and token_id = ?";
    $tuser = $app['db']->fetchAssoc($sql, array($app_id, $app['token_id']));
    if (!$tuser) return $app->json(array('status' => '404', 'message' => 'User not found'), 404);


    try {
        $app['db']->update('users', $user, array('app_id' => $app_id));
    } catch(\Exception $e) {
        return $app->json(array('status' => '500', 'message' => 'Internal Server Error'), 500);
    }

    return $app->json(array('status' => '200', 'message' => 'Success'), 200);
});

// Retrieves pending and complete accounts
$app->get('/api/accounts', function () use ($app) {
    $sql = "SELECT * FROM users WHERE token_id = ?";
    $users = $app['db']->fetchAll($sql, array($app['token_id']));
//    if (!$users) return $app->json(array('message' => 'Users not found'), 204);
    foreach($users as $k => $v) {
        unset($users[$k]['id']);
//        unset($users[$k]['app_id']);
        unset($users[$k]['token_id']);
        unset($users[$k]['password']);
    }
    return $app->json($users);
});


$app->get('/api/accounts/{app_id}', function ($app_id) use ($app) {
    $sql = "SELECT * FROM users WHERE app_id = ? AND token_id = ?";
    $user = $app['db']->fetchAssoc($sql, array($app_id, $app['token_id']));
    if (!$user) return $app->json(array('message' => 'User not found'), 404);

    unset($user['id']);
//    unset($user['app_id']);
    unset($user['token_id']);
    unset($user['password']);

    return $app->json($user);
});


$app->post('/api/accounts/resetpassword', function (Request $request) use ($app) {
    $email = $request->request->get('email');
    $sql = "SELECT * FROM users WHERE email = ? AND token_id = ?";
    $user = $app['db']->fetchAssoc($sql, array($email, $app['token_id']));
    if (!$user) return $app->json(array('status' => '404', 'message' => 'User not found'), 404);

    $verifytoken = md5(uniqid(rand(), true));

    try {
        $app['db']->delete('passwordstoken', array('user_id' => $user['id']));
        $app['db']->insert('passwordstoken', array('user_id' => $user['id'], 'verifytoken' => $verifytoken, 'created' => date('Y-m-d H:i:m')));
    } catch(\Exception $e) {
        return $app->json(array('status' => '500', 'message' => 'Internal Server Error'), 500);
    }

    return $app->json(array('status' => '201', 'verifytoken' => $verifytoken), 201);
});


$app->post('/api/accounts/verifytoken', function (Request $request) use ($app) {
    $email = $request->request->get('email');
    $password = $request->request->get('password');
    $password_confirm = $request->request->get('password_confirm');
    $verifytoken = $request->request->get('verifytoken');

    $constraint = new Assert\Collection(array(
        'email' => array(new Assert\NotBlank(), new Assert\Email(array(
            'message' => 'The email is not a valid.',
            'checkMX' => false,
        ))),
        'password' => array(new Assert\NotBlank()),
        'password_confirm' => array(new Assert\NotBlank()),
        'verifytoken' => array(new Assert\NotBlank()),
    ));

    $errors = $app['validator']->validate(array('email' => $email, 'password' => $password, 'password_confirm' => $password_confirm, 'verifytoken' => $verifytoken), $constraint);

    if (count($errors) > 0) {
        $errs = array();
        foreach ($errors as $error) {
            $errs[$error->getPropertyPath()] = $error->getMessage();
        }

        return $app->json(array('status' => '404', 'message' => 'Not valid data', 'errors' => $errs), 404);
    }

    if ($password != $password_confirm) return $app->json(array('status' => '404', 'message' => 'Passwords does not match'), 404);

    $sql = "SELECT * FROM passwordstoken WHERE verifytoken = ?";
    $verifytoken = $app['db']->fetchAssoc($sql, array($verifytoken));
    if (!$verifytoken) return $app->json(array('status' => '404', 'message' => 'Wrong verify code'), 404);

    $sql = "SELECT * FROM users WHERE email = ? and token_id = ?";
    $tuser = $app['db']->fetchAssoc($sql, array($email, $app['token_id']));
    if (!$tuser) return $app->json(array('status' => '404', 'message' => 'User not found'), 404);

    try {
        $app['db']->update('users', array('password' => $password), array('id' => $verifytoken['user_id']));
        $app['db']->delete('passwordstoken', array('id' => $verifytoken['id']));
    } catch(\Exception $e) {
        return $app->json(array('status' => '500', 'message' => 'Internal Server Error'), 500);
    }

    return $app->json(array('status' => '200', 'message' => 'Password successfully change'), 200);
});

ErrorHandler::register();
//register an error handler
$app->error(function ( \Exception $e, $code ) use ($app) {
    //return your json response here
    $error = array( 'message' => $e->getMessage() );
    return $app->json( array('status' => $code, 'message' => $error), $code );
});

if (php_sapi_name() == "cli") {
    list($_, $method, $path) = $argv;
    $request = Request::create($path, $method, $user);
    $app->run($request);
} else {
    $app->run();
}
