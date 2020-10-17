<?php

namespace akiyatkin\meta;

use infrajs\ans\Ans;
use infrajs\path\Path;
use infrajs\db\Db;
use infrajs\mail\Mail;
use infrajs\access\Access;
use infrajs\lang\Lang;

class Meta {

	public function __construct($opt) {
		$this->action = $opt['action'];
		$this->question = 0;
		$this->src = $opt['src'];
		$this->name = $opt['name'];
		$this->lang = $opt['lang'];
		$this->actions = $opt['actions'] ?? [];
		$this->handlers = $opt['handlers'] ?? []; //Обработки привязанные к action
		$this->args = $opt['args'] ?? []; //Обработки привязанные к параметрам
		$this->vars = $opt['vars'] ?? [];
		$this->params = ['ans'=>[]];
	}
	public function set($pname, $val) {
		if (isset($this->params[$pname])) return $this->fail('repeat', $pname);
		$this->params[$pname] = $val;
	}
	public function init() {
		try {
			return $this->initnow();
		} catch (\Exception $e) {			
			return $this->params['ans'];
		}

	}
	public function initnow() {
		
		$ans = &$this->params['ans'];
		if (!$src = Path::theme($this->src)) return $this->fail('CR018');
		if (!$json = file_get_contents($src)) return $this->fail('CR018');
		$meta = json_decode($json, true);
		$this->meta = $meta;
		if (!$meta) return $this->fail('CR018');		
		$action = $this->action;
		if (!$action) {
		    $ans['meta'] = $meta;
		    return Ans::ret($ans);
		}
		
		$this->params['meta'] = $meta;
		$this->actionmeta = $meta['actions'][$action] ?? [
			'action' => $action
		];

		if (!isset($meta['actions'][$action])) return $this->fail('CR001');
		
		$this->actionmeta = $meta['actions'][$action];
		$this->actionmeta['action'] = $action;
		$this->actionmeta['handlers'] = $this->actionmeta['handlers'] ?? [];



		//Избавляемся от post и get
		$dependencies = $this->meta['dependencies']['get']; //Зависимости по умолчанию, без такого ключа
		$keys = $this->actionmeta['handlers'] ?? [];
		foreach ($keys as $key) {
			if (!isset($this->meta['dependencies'][$key])) continue;
			foreach($this->meta['dependencies'][$key] as $pname => $deps) {
				if (!isset($dependencies[$pname])) $dependencies[$pname] = $deps;
				else $dependencies[$pname] = array_merge($dependencies[$pname], $deps);
			}
		}
		$this->dependencies = $dependencies;
		
		
		//Рекурсивно применяем все зависимости, чтобы точно знать, по плану эта переменная требуется или нет
		//И чтобы была возможность проверить всё		
		$required = array_merge($this->dependencies['default'], $this->actionmeta['required'] ?? []);
		for ($i = 0; $i<sizeof($required); $i++) {
			$pname = $required[$i];
			if (isset($this->dependencies[$pname])) {
				array_splice($required, $i+1, 0, $this->dependencies[$pname]);
			}
		}	
		$required = array_values(array_unique($required));
		
		$in = $required;
		for ($i = 0; $i < sizeof($in); $i++) {
			$pname = $in[$i];
			if (!isset($this->meta['args'][$pname])) {
				array_splice($in, $i, 1);
				$i--;
			}
		}
		
		$this->actionmeta['required'] = array_unique($in);
		//Инициализация закончилась
		$ans['actionmeta'] = $this->actionmeta;
		return $this->ready();
	}
	public function ready() {
		
		//handlers выполняется с Транзакцией если это post
		if (in_array('post', $this->actionmeta['handlers'])) Db::start();
		
		foreach ($this->actionmeta['handlers'] as $hand) {
			//Изменения данных могут делать как будто всё ок, в случае ошибки будет откат
			//Можно делать freeze какбудто заказ отправлен на проверку
			$this->handler($hand); //Может быть исключение ans.result = 0
		}

		$action = $this->action;
		if (!isset($this->actions[$action])) return $this->fail('CR001', $action);
		
		//$this->params[$action] = false;
		
		
		//Может быть исключение ans.result = 0, 
		//положительный результат только return

		$this->exec($this->actions[$action], $action, $this->params[$action]); 
		
		if (in_array('post', $this->actionmeta['handlers'])) {
			if (Db::isstart()) Db::commit(); //Пред error можно сделать commit, так как с исключением сюда не попадаем
		}

		//Для положительного ответа нужен return чтобы зафиксировалась транзакция
		return $this->ret();
	}
	
	
	public function &_arg($pname) {
		//Если параметра нет в списке нужных и это параметр из адресной строки - сообщаем об ошибки
		if (!in_array($pname, $this->actionmeta['required'])) {
			//if (isset($this->args[$pname])) return $this->var($pname);
			return $this->fail("Параметр $pname не указан в зависимостях действия", $pname);
		}
		if (isset($this->params[$pname])) return $this->params[$pname];

		// if (isset($this->dependencies[$pname])) {
		// 	foreach ($this->dependencies[$pname] as $dname) {
		// 		//if (isset($this->params[$pname])) continue;
		// 		$this->get($dname);
		// 	}
		// 	if (isset($this->params[$pname])) return $this->params[$pname];
		// }
		$val = Ans::REQS($pname);

		//Повторяющиеся обработки на параметры

		if (is_null($val)) return $this->fail('required', $pname);

		$args = $this->meta['args'][$pname];
		if (in_array('notempty', $args)) {
			if (!$val && $val !== "0") return $this->fail('required', $pname);
		}
		if (in_array('int', $args)) {
			$val = (int) $val;
			if (strlen($val) > 100) return $this->fail('required', $pname);
		} else if (in_array('text', $args)) {
			$val = (string) $val;
			if (strlen($val) > 65000) return $this->fail('required', $pname);
		} else if (in_array('intarray', $args)) {
			if (strlen($val) > 65000) return $this->fail('required', $pname);
			if (!$val) return $this->fail('required', $pname);
			$val = explode(',', $val);
			foreach ($val as $i => $id) {
				$val[$i] = (int) $id;
				if (!$val[$i]) return $this->fail('required', $pname);
			}
			
		} else {
			$val = (string) $val;
			if (strlen($val) > 255) return $this->fail('required', $pname);
		}

		$this->params[$pname] = &$val;
		if (isset($this->args[$pname])) {	
			$this->exec($this->args[$pname], $pname, $this->params[$pname]);
		}
		return $this->params[$pname];
	}
	public function exec($fn, $pname, &$val) {
		$func = \Closure::bind($fn, $this);
		return $func($val, $pname);
	}
	
	public function &var($pname) {
		//Если параметра нет в списке нужных и это параметр из адресной строки - сообщаем об ошибки
		$rname = explode('?', $pname)[0];
		if (isset($this->params[$rname])) return $this->params[$rname];

		//required надо проверять
		//if (!in_array($pname, $this->actionmeta['vars'])) return $this->fail('required', $pname);
		if (!isset($this->vars[$rname])) return $this->fail("У параметра нет обработчика в vars или он не зарегистрирован в списке аргументов args $rname", $rname);
		
		// if (isset($this->dependencies[$pname])) {
		// 	foreach ($this->dependencies[$pname] as $dname) {
		// 		//if (isset($this->params[$pname])) return $this->fail('recursion', $pname);
		// 		$this->get($dname);
		// 	}
		// 	if (isset($this->params[$pname])) return $this->params[$pname];
		// }
		$vname = preg_split('/[\*\#]/', $rname)[0];
		$this->params[$rname] = false;
		if ($rname != $pname) {
			$this->question++;
			try {
				$this->exec($this->vars[$rname], $vname, $this->params[$rname]);
			} catch(\Exception $e) {				
				$this->params[$rname] = false;
				//if (empty($this->params['ans']['result'])) throw $e;
			}
			$this->question--;
		} else {
			$this->exec($this->vars[$rname], $vname, $this->params[$rname]);
		}
		return $this->params[$rname];
	}
	// public function getAll () {
	// 	return $this->gets($this->actionmeta['required']);
	// }
	// public function handlers($pnames) {
	// 	$pnames = func_get_args();
	// 	return array_map([$this, 'handler'], $pnames);
	// }
	public function gets($pnames) {
		foreach ($pnames as $pname) {
			$vname = preg_split('/[#\?\*]/', $pname)[0];
			$res[$vname] = &$this->get($pname);
		}
		return $res;
	}
	public function &get($pname) {
		$rname = explode('?', $pname)[0];
		
		if (isset($this->meta['args'][$pname])) {
			return $this->_arg($pname);
		} else if (in_array($rname, $this->meta['vars'])) {
			return $this->var($pname);
		}
		else return $this->fail('illegal', $pname);
	}
	public $ready = false;
	public function ret($code = false, $pname = false) {
		extract($this->gets(['ans','lang']), EXTR_REFS);
		if ($this->ready) return $ans;
		$this->ready = true;
		if (Access::isDebug()) $this->addBacktrace();
		if (!$code) {
			return Lang::ret($ans);
		}
		if (!$pname) {
			return Lang::ret($ans, $lang, $this->name.'.'.$code);
		}
		$ans['payload'] = $pname;
		return Lang::rettpl($ans, $lang, $this->name.'.'.$code);
	}

	public function handler($hand){

		if (empty($this->handlers[$hand])) return $this->fail('handler', $hand);

		if (is_callable($this->handlers[$hand])) {
			$fn = \Closure::bind($this->handlers[$hand], $this);
			$this->handlers[$hand] = true; //Может быть рекурсия?
			$fn();
			
		}
		return $this->handlers[$hand];
	}
	public function addBacktraceLines($count = 8) {
		$back = debug_backtrace();
		array_splice($back, sizeof($back) - 5);
		foreach ($back as $i => $e) {
			unset($back[$i]['object']);
			$name = basename($e['file'] ?? '');
			if ($name == 'Meta.php') unset($back[$i]);
			if (empty($back[$i]['class'])) continue;
		}
		unset($back[0]);
		$lines = [];
		$c = 0;
		foreach ($back as $i => $e) {
			if (empty($e['file'])) continue;
			if (++$c > $count) break;

			$lines[] = $e['line'];
		}
		return implode('-', $lines);
	}
	public function addBacktrace() {
		$ans = &$this->params['ans'];
		$back = debug_backtrace();
		foreach ($back as $i => $e) {
			unset($back[$i]['object']);
		}
		unset($back[0]);
		$lines = [];
		foreach ($back as $i => $e) {
			if (empty($e['file'])) continue;
			$lines[] = basename($e['file']).', '.$e['line'].', '.$e['function'];
		}
		$ans['backtrace'] = $lines;
	}
	public function err($code, $pname = false) {
		if ($this->question) throw new \Exception();
		extract($this->gets(['ans','lang']), EXTR_REFS);
		if (Access::isDebug()) $this->addBacktrace();
		$ans['params'] = array_keys($this->params);
		if (!$pname) {
			$ans = Lang::err($ans, $lang, $this->name.'.'.$code);
			throw new \Exception();
		} 
		$ans['missing'] = $pname;
		$ans = Lang::errtpl($ans, $lang, $this->name.'.'.$code);
		throw new \Exception();
	}
	public function fail($code, $pname = false) {
		if ($this->question) throw new \Exception();
		$ans = &$this->params['ans'];
		$lang = $this->params['lang'] ?? $this->lang;
		$ans['params'] = array_keys($this->params);

		if (Access::isDebug()) $this->addBacktrace();
		$line = $this->addBacktraceLines();

		if (!$pname) {			
			$ans = Lang::fail($ans, $lang, $this->name.'.'.$code.'.'.$this->actionmeta['action'].'-'.$line);
			throw new \Exception();
		}
		$ans['missing'] = $pname;
		$ans = Lang::failtpl($ans, $lang, $this->name.'.'.$code);
		throw new \Exception();
	}

}