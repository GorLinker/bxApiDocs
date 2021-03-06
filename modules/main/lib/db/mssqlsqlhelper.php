<?php
namespace Bitrix\Main\DB;

class MssqlSqlHelper extends SqlHelper
{

	/**
	 * Identificator escaping - left char
	 * @return string
	 */
	static public function getLeftQuote()
	{
		return '[';
	}

	/**
	 * Identificator escaping - left char
	 * @return string
	 */
	static public function getRightQuote()
	{
		return ']';
	}

	static public function getQueryDelimiter()
	{
		return "\nGO";
	}

	public static function forSql($value, $maxLength = 0)
	{
		return str_replace("\x00", "", ($maxLength > 0) ? str_replace("'", "''", substr($value, 0, $maxLength)) : str_replace("'", "''", $value));
	}

	static public function getCurrentDateTimeFunction()
	{
		return "GETDATE()";
	}

	static public function getCurrentDateFunction()
	{
		return "convert(datetime, cast(year(getdate()) as varchar(4)) + '-' + cast(month(getdate()) as varchar(2)) + '-' + cast(day(getdate()) as varchar(2)), 120)";
	}

	static public function addSecondsToDateTime($seconds, $from = null)
	{
		if ($from === null)
		{
			$from = static::getCurrentDateTimeFunction();
		}

		return 'DATEADD(second, '.$seconds.', '.$from.')';
	}

	static public function getDatetimeToDateFunction($value)
	{
		return 'DATEADD(dd, DATEDIFF(dd, 0, '.$value.'), 0)';
	}

	public function formatDate($format, $field = null)
	{
		if ($field === null)
		{
			return '';
		}

		$result = array();

		foreach (preg_split("#(YYYY|MMMM|MM|MI|M|DD|HH|H|GG|G|SS|TT|T)#", $format, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part)
		{
			switch ($part)
			{
				case "YYYY":
					$result[] = "\n\tCONVERT(varchar(4),DATEPART(yyyy, $field))";
					break;
				case "MMMM":
					$result[] = "\n\tdatename(mm, $field)";
					break;
				case "MM":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(mm, $field)))+CONVERT(varchar(2),DATEPART(mm, $field))";
					break;
				case "MI":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(mi, $field)))+CONVERT(varchar(2),DATEPART(mi, $field))";
					break;
				case "M":
					$result[] = "\n\tCONVERT(varchar(3), $field,7)";
					break;
				case "DD":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(dd, $field)))+CONVERT(varchar(2),DATEPART(dd, $field))";
					break;
				case "HH":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(hh, $field)))+CONVERT(varchar(2),DATEPART(hh, $field))";
					break;
				case "H":
					$result[] = "\n\tCASE WHEN DATEPART(HH, $field) < 13 THEN RIGHT(REPLICATE('0',2) + CAST(datepart(HH, $field) AS VARCHAR(2)),2) ELSE RIGHT(REPLICATE('0',2) + CAST(datepart(HH, dateadd(HH, -12, $field)) AS VARCHAR(2)), 2) END";
					break;
				case "GG":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(hh, $field)))+CONVERT(varchar(2),DATEPART(hh, $field))";
					break;
				case "G":
					$result[] = "\n\tCASE WHEN DATEPART(HH, $field) < 13 THEN RIGHT(REPLICATE('0',2) + CAST(datepart(HH, $field) AS VARCHAR(2)),2) ELSE RIGHT(REPLICATE('0',2) + CAST(datepart(HH, dateadd(HH, -12, $field)) AS VARCHAR(2)), 2) END";
					break;
				case "SS":
					$result[] = "\n\tREPLICATE('0',2-LEN(DATEPART(ss, $field)))+CONVERT(varchar(2),DATEPART(ss, $field))";
					break;
				case "TT":
					$result[] = "\n\tCASE WHEN DATEPART(HH, $field) < 13 THEN 'AM' ELSE 'PM' END";
					break;
				case "T":
					$result[] = "\n\tCASE WHEN DATEPART(HH, $field) < 13 THEN 'AM' ELSE 'PM' END";
					break;
				default:
					$result[] = "'".$this->forSql($part)."'";
					break;
			}
		}

		return join("+", $result);
	}

	static public function getConcatFunction()
	{
		$str = "";
		$ar = func_get_args();
		if (is_array($ar))
			$str .= implode(" + ", $ar);
		return $str;
	}

	static public function getIsNullFunction($expression, $result)
	{
		return "ISNULL(".$expression.", ".$result.")";
	}

	static public function getLengthFunction($field)
	{
		return "LEN(".$field.")";
	}

	static public function getToCharFunction($expr, $length = 0)
	{
		return "CAST(".$expr." AS CHAR".($length > 0 ? "(".$length.")" : "").")";
	}

	static public function getDateTimeToDbFunction(\Bitrix\Main\Type\DateTime $value, $type = \Bitrix\Main\Type\DateTime::DATE_WITH_TIME)
	{
		$customOffset = $value->getValue()->getOffset();

		$serverTime = new \Bitrix\Main\Type\DateTime();
		$serverOffset = $serverTime->getValue()->getOffset();

		$diff = $customOffset - $serverOffset;
		$valueTmp = clone $value;

		$valueTmp->getValue()->sub(new \DateInterval(sprintf("PT%sS", $diff)));

		$format = ($type == \Bitrix\Main\Type\DateTime::DATE_WITHOUT_TIME ? "Y-m-d" : "Y-m-d H:i:s");
		$date = "CONVERT(datetime, '".$valueTmp->format($format)."', 120)";

		return $date;
	}

	static public function getDateTimeFromDbFunction($fieldName)
	{
		return "CONVERT(varchar(19), ".$fieldName.", 120)";
	}

	public function prepareInsert($tableName, $arFields)
	{
		$strInsert1 = "";
		$strInsert2 = "";

		$arColumns = $this->dbConnection->getTableFields($tableName);
		foreach ($arColumns as $columnName => $columnInfo)
		{
			if (array_key_exists($columnName, $arFields))
			{
				$strInsert1 .= ", ".$columnName."";
				$strInsert2 .= ", ".$this->convertValueToDb($arFields[$columnName], $columnInfo);
			}
			elseif (array_key_exists("~".$columnName, $arFields))
			{
				$strInsert1 .= ", ".$columnName."";
				$strInsert2 .= ", ".$arFields["~".$columnName];
			}
		}

		if ($strInsert1 != "")
		{
			$strInsert1 = " ".substr($strInsert1, 2)." ";
			$strInsert2 = " ".substr($strInsert2, 2)." ";
		}

		return array($strInsert1, $strInsert2, array());
	}

	protected function convertValueToDb($value, array $columnInfo)
	{
		if ($value === null)
		{
			return "NULL";
		}

		if ($value instanceof SqlExpression)
		{
			return $value->compile();
		}

		switch ($columnInfo["TYPE"])
		{
			case "datetime":
			case "timestamp":
				if (empty($value))
					$result = "NULL";
				else
					$result = $this->getDatetimeToDbFunction($value, \Bitrix\Main\Type\DateTime::DATE_WITH_TIME);
				break;
			case "date":
				if (empty($value))
					$result = "NULL";
				else
					$result = $this->getDatetimeToDbFunction($value, \Bitrix\Main\Type\DateTime::DATE_WITHOUT_TIME);
				break;
			case "int":
			case "tinyint":
			case "smallint":
			case "bigint":
				$result = "'".intval($value)."'";
				break;
			case "decimal":
			case "numeric":
				$result = "'".round(doubleval($value), intval($columnInfo["NUMERIC_SCALE"]))."'";
				break;
			case "real":
			case "float":
				$result = "'".doubleval($value)."'";
				break;
			case "image":
				$result = $value;
				break;
			default:
				$result = "'".$this->forSql($value, $columnInfo['CHARACTER_MAXIMUM_LENGTH'])."'";
				break;
		}

		return $result;
	}

	static public function getTopSql($sql, $limit, $offset = 0)
	{
		$offset = intval($offset);
		$limit = intval($limit);

		if ($offset > 0 && $limit <= 0)
			throw new \Bitrix\Main\ArgumentException("Limit must be set if offset is set");

		if ($limit > 0)
		{
			if ($offset <= 0)
			{
				$sql = preg_replace("/^\\s*SELECT/i", "SELECT TOP ".$limit, $sql);
			}
			else
			{
				if (preg_match("#(\\s+order\\s+by(\\s+[a-z0-9_.]+(\\s+(asc|desc))?\\s*,)*(\\s+[a-z0-9_.]+(\\s+(asc|desc))?)\\s*)$#i", $sql, $matches))
				{
					$orderBy = $matches[1];
					$sqlTmp = substr($sql, 0, -strlen($orderBy));
				}
				else
				{
					$orderBy = "ORDER BY (SELECT 1)";
					$sqlTmp = $sql;
				}

				$sqlTmp = preg_replace(
					"/^\\s*SELECT/i",
					"SELECT ROW_NUMBER() OVER (".$orderBy.") AS ROW_NUMBER_ALIAS,",
					$sqlTmp
				);

				$sql =
					"WITH ROW_NUMBER_QUERY_ALIAS AS (".$sqlTmp.") ".
					"SELECT * ".
					"FROM ROW_NUMBER_QUERY_ALIAS ".
					"WHERE ROW_NUMBER_ALIAS BETWEEN ".$offset." AND ".($offset + $limit - 1)."";
			}
		}
		return $sql;
	}

}
