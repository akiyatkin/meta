<?php

use akiyatkin\meta\Meta;
use infrajs\rest\Rest;
use infrajs\ans\Ans;

$context = new Meta(); //Если указан путь, то всё сверяется с meta.json

/*
argument
handler
function - без кэша, default
action
variable

*/

//action запускаться может перед какой-то обработкой или после. Всё может работать без meta.json
//Обработка не зависит от родителя.
$context->addFunction('post', function () {
	$submit = ($_SERVER['REQUEST_METHOD'] === 'POST' || Ans::GET('submit', 'bool'));
	if (!$submit) return $this->fail('lang.post');
});

//Обработка с зависимостью от родителя
//handler, приходит какое-то стартовое значение, смысл которого зависит от того к чему handler привязан (arg, action, handler, var)
$context->addHandler('notempty', ['post'], function ($a,$b, $notempty, $pname) {
	if (!$notempty) return $this->fail('empty', $pname);
});

$context->addFunction('Check the legality of the action', function () {
	extract($this->gets(['action']));
});

//Обработка не зависиот от родителя. Приходит Request, наличие обязательно, из адреса не запускается
$context->addArgument('order_id', ['notempty']);
$context->addArgument('order_nick', ['notempty']);

$context->addVariable('order_id@valid', function () {

	if ($this->is('order_nick')) {		
		$order_nick = $this->get('order_nick');
		$order_id = $order_nick;
	} else {
		$order_id = $this->get('order_id');
	}
	return $order_id;
	
}, ['post', 'notempty', 'Check the legality of the action']);

$context->addVariable('order', function () {
	extract($this->gets(['order_id@valid']));
	$order = [];
	return $order;
});

$context->addAction('getorder', ['order_nick'], function ($beforename) {
	extract($this->gets(['order_id@valid']));
	$order = [];
	$this->ans['order'] = $order;
});

$context->addAction('fastorder', ['order_nick'], function () {
	extract($this->gets(['order_id@valid']));
	$this->ans['tadam'] = 1;
});
$context->add('', function () {
	
});
$context->add('action', function () {
	
});
$action = Rest::first();
return $context->init($action);