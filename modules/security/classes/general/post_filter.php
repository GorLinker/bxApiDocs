<?php

class CSecurityXSSDetect
{

	private $quotes = array();

	private $action = "filter";
	private $doLog = false;

	/** @var CSecurityXSSDetectVariables */
	private $variables = null;

	public function __construct($pCustomOptions = array())
	{
		if(isset($pCustomOptions["action"]))
		{
			$this->setAction($pCustomOptions["action"]);
		}
		else
		{
			$this->setAction(COption::GetOptionString("security", "filter_action"));
		}

		if(isset($pCustomOptions["log"]))
		{
			$this->setLog($pCustomOptions["log"]);
		}
		else
		{
			$this->setLog(COption::GetOptionString("security", "filter_log"));
		}
	}

	/**
	 * @param $pContent
	 */
	public static function OnEndBufferContent(&$pContent)
	{
		if(CSecurityFilterMask::Check(SITE_ID, $_SERVER["REQUEST_URI"]))
			return;

		$filter = new CSecurityXSSDetect();
		$pContent = $filter->process($pContent);
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public function process($content)
	{
		if(!preg_match('/<script/i' ,$content))
			return $content;

		$this->variables = new CSecurityXSSDetectVariables();
		$this->extractVariablesFromArray("\$_GET", $_GET);
		$this->extractVariablesFromArray("\$_POST", $_POST);
		$this->extractVariablesFromArray("\$_COOKIE", $_COOKIE);
		if(!$this->variables->isEmpty())
			return $this->filter($content);
		else
			return $content;
	}

	/**
	 * @return array
	 */
	public function getQuotes()
	{
		return $this->quotes;
	}

	/**
	 * @param string $pString
	 * @param bool $pIsSaveQuotes
	 * @return mixed
	 */
	public function removeQuotedStrings($pString, $pIsSaveQuotes = true)
	{
		// http://stackoverflow.com/questions/5695240/php-regex-to-ignore-escaped-quotes-within-quotes
		if($pIsSaveQuotes)
		{
			$this->quotes = array();
			return preg_replace_callback('/(
				"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"                           # match double quoted string
				|
				\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'                       # match single quoted string
				|
				(?s:\\/\\*.*?\\*\\/)                                     # multiline comments
				|
				\\/\\/.*?(?:\\n|$)                                       # singleline comments
				|
				string.replace\\(\\/[^\\/\\\\]*(?:\\\\.[^\\/\\\\]*)*\\/  # an JS regexp
			)/x', array($this, "pushQuote"), $pString);
		}
		else
		{
			return preg_replace('/(
				"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"                           # match double quoted string
				|
				\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'                       # match single quoted string
				|
				(?s:\\/\\*.*?\\*\\/)                                     # multiline comments
				|
				\\/\\/.*?(?:\\n|$)                                       # singleline comments
				|
				string.replace\\(\\/[^\\/\\\\]*(?:\\\\.[^\\/\\\\]*)*\\/  # an JS regexp
			)/x', '', $pString);
		}
	}

	/**
	 * @param string $pAction
	 */
	protected function setAction($pAction)
	{
		$this->action = $pAction;
	}

	/**
	 * @param string $pLog - only Y/N
	 */
	protected function setLog($pLog)
	{
		if(is_string($pLog) && $pLog == "Y")
		{
			$this->doLog = true;
		}
		else
		{
			$this->doLog = false;
		}
	}

	/**
	 * @param $pName
	 * @param $pValue
	 * @param $pSourceScript
	 * @return mixed
	 */
	protected function logVariable($pName, $pValue, $pSourceScript)
	{
		if(defined("ANTIVIRUS_CREATE_TRACE"))
			$this->CreateTrace($pName, $pValue, $pSourceScript);
		return CSecurityEvent::getInstance()->doLog("SECURITY", "SECURITY_FILTER_XSS2", $pName, $pValue);
	}

	/**
	 * @param $var_name
	 * @param $str
	 * @param $script
	 */
	protected function CreateTrace($var_name, $str, $script)
	{
		$cache_id = md5($var_name.'|'.$str);
		$fn = $_SERVER["DOCUMENT_ROOT"]."/bitrix/cache/virus.db/".$cache_id.".flt";
		if(!file_exists($fn))
		{
			CheckDirPath($fn);
			$f = fopen($fn, "wb");

			fwrite($f, $var_name.": ".$str);
			fwrite($f, "\n------------\n".$script);
			fwrite($f, "\n------------------------------\n\$_SERVER:\n");
			foreach($_SERVER as $k=>$v)
				fwrite($f, $k." = ".$v."\n");

			fclose($f);
			@chmod($fn, BX_FILE_PERMISSIONS);
		}
	}

	/**
	 * @param string $pQuote
	 * @return string
	 */
	protected function pushQuote($pQuote)
	{
		$this->quotes[] = $pQuote[0];
		return "";
	}

	/**
	 * @param string $pString
	 * @param array $pPatterns
	 * @return bool
	 */
	protected static function isFoundInString($pString, $pPatterns)
	{
		foreach($pPatterns as $pattern)
		{
			if(isset($pattern["variable_len"]))
				$isFound = strlen($pString) > $pattern["variable_len"] && preg_match($pattern["pattern"], $pString);
			else
				$isFound = preg_match($pattern, $pString);

			if($isFound)
				return true;
		}
		return false;
	}

	/**
	 * @param string $pBody
	 * @return bool
	 */
	protected function isDangerBody($pBody)
	{
		if(self::isFoundInString($pBody ,$this->variables->getQuoteSearchPattern()))
		{
			return true;
		}
		else
		{
			$bodyWithoutQuotes = $this->removeQuotedStrings($pBody, false);
			if(self::isFoundInString($bodyWithoutQuotes, $this->variables->getSearchPattern()))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $pBody
	 * @return string
	 */
	protected function getFilteredScriptBody($pBody)
	{
		if($this->isDangerBody($pBody))
		{
//                if($this->mIsLogNeeded)
//			      {
//                    $this->logVariable($var_name, $value, $str);
//                }
			if($this->action !== "none")
			{
				$pBody = "";
			}
		}

		return $pBody;
	}

	/**
	 * @param array $strs
	 * @return string
	 */
	protected function getFilteredScript($strs)
	{
		if(trim($strs[2]) === "")
			return $strs[0];
		else
			return $strs[1].$this->getFilteredScriptBody($strs[2]).$strs[3];
	}

	/**
	 * @param string $pString
	 * @return string
	 */
	protected function filter($pString)
	{
		$stringLen = CUtil::BinStrlen($pString) * 2;
		CUtil::AdjustPcreBacktrackLimit($stringLen);

		return preg_replace_callback("/(<script[^>]*>)(.*?)(<\\/script[^>]*>)/is", array($this, "getFilteredScript"), $pString);
	}

	/**
	 * @param string $pName
	 * @param string $pValue
	 */
	protected function addVariable($pName, $pValue)
	{
		if(!is_string($pValue))
			return;
		if(strlen($pValue) <= 2)
			return; //too short
		if(preg_match("/^[^,;\'\"+\-*\/\{\}\[\]\(\)&\\|=\\\\]*\$/", $pValue))
			return; //there is no potantially dangerous code
		if(preg_match("/^[,0-9_-]*\$/", $pValue))
			return; //there is no potantially dangerous code
		if($pName === '$_COOKIE[__utmz]' && preg_match("/^[0-9.]++(utm[a-z]{3}=\(?([a-z\/0-1.]++|\(not provided\))\)?\|?)++\$/i", $pValue))
			return; //there is no potantially dangerous code, google analytics

		$this->variables->addVariable($pName, str_replace(chr(0), "", $pValue));
	}

	/**
	 * @param string $pName
	 * @param array $pArray
	 */
	protected function extractVariablesFromArray($pName, array $pArray)
	{
		if(is_array($pArray))
		{
			foreach($pArray as $key => $value)
				$this->addVariable($pName."[".$key."]", $value);
		}
	}

}
