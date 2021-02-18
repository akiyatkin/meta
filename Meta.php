<?php

namespace akiyatkin\meta;

use infrajs\ans\Ans;
use infrajs\path\Path;
use infrajs\db\Db;
use infrajs\mail\Mail;
use infrajs\access\Access;
use infrajs\lang\Lang;
use infrajs\cache\CacheOnce;
use infrajs\config\Config;

//lang может быть использован из адреса.
//lang meta и lang приложения не могут отсутствовать/не совпадать.
class MetaException extends \Exception {
}
class Meta {	
	use CacheOnce; //once($name, $args, $fn) , $once
	public $list = [];

	public function __construct($name = 'meta', $lang = 'ru', $src = false, $base = false) {
		$this->addAction('', function () {
			return $this->empty();
		});
	}
	public function init($opt = []) {
		extract(array_merge([
			'handlers'=> [],
			'name' => 'meta',
			'base' => false,
			'lang' => 'ru',
			'action' => explode('/', str_replace($opt['base'] ?? '/-'.$opt['name'], '', explode('?',$_SERVER['REQUEST_URI'],2)[0]), 3)[1] ?? ''
		], $opt), EXTR_REFS);


		$this->action = $action;
		$this->ans = [];//'action'=> $this->action
		$this->name = $name;
		$conf = Config::get($name);
		$this->lang = Ans::REQ('lang', $conf['lang']['list'], $conf['lang']['def']);
		
		try {
			if (!$this->lang) {
				$this->lang = $conf['lang']['def'];
				return $this->fail('meta.required','lang');
			}
			if (empty($this->list[$this->action]['response'])) {
				$this->fail('meta.badrequest');
			}
			
			foreach ($handlers as $hand) {
				$this->get($hand);
			}
			
			$res = $this->get($this->action);
			
			if (!is_null($res)) { //Если ничего не возвращаем, значит сами разрулили с ответом
				$this->ans[$this->action] = $res;
				return $this->ret();	
			}
			if ($this->ans) return Ans::ans($this->ans);
		} catch (MetaException $e) {
			return $this->ans;
		}

	}
	public function &add($pname, $a1 = null, $a2 = null, $a3 = null) {
		$after = null;
		$before = null;
		$func = null;
		if (is_callable($a1)) {			
			$func = $a1;
			$after = $a2;
		} else if (is_callable($a2)) {
			$func = $a2;
			$before = $a1;
			$after = $a3;
		} else if (is_string($a1)) {
			$after = $a2;
		} else if (is_string($a2)) {
			$before = $a1;
			$after = $a3;
		} else {
			if ($a2) {
				$before = $a1;
				$after = $a2;
			} else {
				$before = $a1;
			}
		}
		$this->list[$pname] = [
			'name' => $pname,
			'process' => false,
			'request' => false, //Нужно ли брать из REQUEST
			'required' => false, //Нужно ли выкидывать исключение если нет request
			'response' => false,
			'result' => null,
			'ready' => false,
			'cache' => null,
			'type' => null,
			'func' => $func,
			'after' => $after,
			'before' => $before
		];
		return $this->list[$pname];
	}
	public function addHandler($pname, $a1 = null, $a2 = null, $a3 = null) {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['type'] = 'handler';
		$opt['response'] = false;
		$opt['request'] = false;
		$opt['cache'] = true;
		$opt['required'] = false;
	}
	public function addArgument($pname, $a1 = null, $a2 = null, $a3 = null) {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['type'] = 'argument';
		$opt['response'] = false;
		$opt['request'] = true;
		$opt['cache'] = true;
		$opt['required'] = true;
	}
	public function addVariable($pname, $a1 = null, $a2 = null, $a3 = null) {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['type'] = 'variable';
		$opt['response'] = false;
		$opt['request'] = false;
		$opt['cache'] = true;
		$opt['required'] = false;
	}
	public function addAction($pname, $a1 = null, $a2 = null, $a3 = null) {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['type'] = 'action';
		$opt['response'] = true;
		$opt['request'] = false;
		$opt['cache'] = true;
		$opt['required'] = false;
	}
	public function addFunction($pname, $a1 = null, $a2 = null, $a3 = null) {
		$opt = &$this->add($pname, $a1, $a2, $a3);
		$opt['type'] = 'function';
		$opt['response'] = false;
		$opt['request'] = false;
		$opt['cache'] = false;
		$opt['required'] = false;
	}
	public function empty() {	
		$actions = implode(', ', array_keys(array_filter($this->list, function ($h) {
			if (!$h['name']) return false;
			return $h['type'] == 'action';
		})));
	 	return $this->err('meta.emptyrequest', $actions);
	}
	public function is($pname) {
		return $this->list[$pname]['ready'];
	}
	
	public function gets($pnames) {
		foreach ($pnames as $pname) {
			$vname = preg_split('/[\#\*@\?]/', $pname)[0];
			$res[$vname] = &$this->get($pname);
		}
		return $res;
	}

	public function &get($pname, $parentvalue = null, $parentname = null) {
		if (empty($this->list[$pname])) $this->_fail('meta.notfound', $pname);
		$opt = &$this->list[$pname];

		if ($opt['cache'] && $opt['ready']) return $opt['result'];

		if ($opt['process']) $this->fail('meta.recursion', $pname);
		$opt['process'] = true;

		$forname = $parentname ?? $pname;
		
		if ($opt['request']) {
			$res = Ans::REQS($pname);
		} else {
			$res = $parentvalue;
		}
		if ($opt['before']) {
			foreach ($opt['before'] as $n) {
				$r = $this->get($n, $res, $forname);
				if (!is_null($r)) $res = $r;
			}
		}
		if ($opt['required']) {
			if (is_null($res)) $this->_fail('meta.required', $pname);
		}
		if ($opt['func']) {	
			$r = \Closure::bind($opt['func'], $this)($res, $forname);
			if (!is_null($r)) $res = $r;
		}

		if ($opt['after']) {
			foreach ($opt['after'] as $n) {
				$r = $this->get($n, $res, $pname);
				if (!is_null($r)) $res = $r;
			}	
		}

		$opt['ready'] = true;
		$opt['result'] = &$res;
		$opt['process'] = false;
		
		return $opt['result'];
	}


	// public function addBacktraceLines($count = 3) {
	// 	$back = debug_backtrace();
	// 	array_splice($back, sizeof($back) - 5);
	// 	foreach ($back as $i => $e) {
	// 		unset($back[$i]['object']);
	// 		$name = basename($e['file'] ?? '');
	// 		if ($name == 'Meta.php') unset($back[$i]);
	// 		if (empty($back[$i]['class'])) continue;
	// 	}
	// 	unset($back[0]);
	// 	$lines = [];
	// 	$c = 0;
	// 	foreach ($back as $i => $e) {
	// 		if (empty($e['file'])) continue;
	// 		if (++$c > $count) break;

	// 		$lines[] = $e['line'];
	// 	}
	// 	return implode('-', $lines);
	// }
	// public function addBacktrace() {
	// 	$ans = &$this->ans;
	// 	$back = debug_backtrace();
	// 	foreach ($back as $i => $e) {
	// 		unset($back[$i]['object']);
	// 	}
	// 	unset($back[0]);
	// 	$lines = [];
	// 	foreach ($back as $i => $e) {
	// 		if (empty($e['file'])) continue;
	// 		if ($i > sizeof($back) - 5) continue;
	// 		//$name = basename($e['file'] ?? '');
	// 		//if ($name == 'Meta.php') continue;
	// 		$lines[] = basename($e['file']).', '.$e['line'].', '.$e['function'];
	// 	}
	// 	$ans['backtrace'] = $lines;
	// }

	public function _fail($namecode, $pname = null) {
		$ans = &$this->ans;
		$lang = $this->list['lang']['result'] ?? $this->lang;
		if (Access::isDebug()) {
			//$this->addBacktrace();
			$ans['params'] = array_keys(array_filter($this->list, function ($opt) {
				return $opt['ready'] || $opt['process'];
			}));
		}

		
		

		if (is_null($pname)) {
			//Lang::fail($ans, $lang, $namecode.'.'.$this->action.'-'.$this->addBacktraceLines());
			$ans = Lang::fail($ans, $lang, $namecode.'#'.$this->action);
			throw new MetaException();
		}
		$ans['payload'] = $pname;
		$ans = Lang::failtpl($ans, $lang, $namecode);
		throw new MetaException();
	}
	public function fail($code = null, $pname = null) {
		if (!$code) {
			Lang::fail($this->ans);
			throw new MetaException();
		}
		//if (!$code) return Lang::err($this->ans);
		return $this->_fail($this->name.'.'.$code, $pname);
	}

	public function _err($namecode = null, $pname = null) {
		$ans = &$this->ans;
		if (Access::isDebug()) {
			//$this->addBacktrace();
			$ans['params'] = array_keys(array_filter($this->list, function ($opt) {
				return $opt['ready'] || $opt['process'];
			}));
		}

		if (is_null($pname)) {
			$ans = Lang::err($ans, $this->lang, $namecode);
			throw new MetaException();
		} 
		$ans['payload'] = $pname;
		$ans = Lang::errtpl($ans, $lang, $namecode);
		throw new MetaException();
	}
	public function err($code = null, $pname = null) {
		if (!$code) return $this->_err();
		return $this->_err($this->name.'.'.$code, $pname);
	}

	public function _ret($namecode = null, $pname = null) {
		$ans = &$this->ans;
		if (Access::isDebug()) {
			//$this->addBacktrace();
			$ans['params'] = array_keys(array_filter($this->list, function ($opt) {
				return $opt['ready'] || $opt['process'];
			}));
		}

		if (is_null($pname)) {
			$ans = Lang::ret($ans, $this->lang, $namecode);
			throw new MetaException();
		}

		$ans['payload'] = $pname;
		$ans = Lang::rettpl($ans, $lang, $namecode);
		throw new MetaException();
	}
	public function ret($code = null, $pname = null) {
		if (!$code) return $this->_ret();
		return $this->_ret($this->name.'.'.$code, $pname);
	}

}