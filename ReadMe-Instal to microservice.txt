Adding graphql to microservice

1. edit app/config/routes.php

//graphql routes
$collection = new MicroCollection();
$controller = new \Ola\Common\Controllers\GraphqlController();
$collection->setHandler($controller);
$collection->get('/graphql', 'indexAction');
$app->mount($collection);

