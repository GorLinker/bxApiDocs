<?
class CAllPullStack
{
	// receive messages on stack
	// only works in PULL mode
	public static function Get($channelId, $lastId = 0)
	{
		global $DB;

		$newLastId = $lastId;
		$arMessage = Array();
		$strSql = "
				SELECT ps.ID, ps.MESSAGE
				FROM b_pull_stack ps ".($lastId > 0? '': 'LEFT JOIN b_pull_channel pc ON pc.CHANNEL_ID = ps.CHANNEL_ID')."
				WHERE ps.CHANNEL_ID = '".$DB->ForSQL($channelId)."'".($lastId > 0? " AND ps.ID > ".intval($lastId): " AND ps.ID > pc.LAST_ID" );
		$dbRes = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
		while ($arRes = $dbRes->Fetch())
		{
			if ($newLastId < $arRes['ID'])
				$newLastId = $arRes['ID'];

			$data = unserialize($arRes['MESSAGE']);
			$data['id'] = $arRes['ID'];

			$arMessage[] = $data;
		}

		if ($lastId < $newLastId)
			CPullChannel::UpdateLastId($channelId, $newLastId);

		return $arMessage;
	}


	// add a message to stack
	public static function AddByChannel($channelId, $arParams = Array())
	{
		global $DB;

		if (strlen($arParams['module_id']) > 0 || strlen($arParams['command']) > 0)
		{
			$arData = Array(
				'module_id' => $arParams['module_id'],
				'command' => $arParams['command'],
				'params' => is_array($arParams['params'])? $arParams['params']: Array(),
			);
			if (CPullOptions::GetNginxStatus())
			{
				$message = CUtil::PhpToJsObject(Array('CHANNEL_ID' => $channelId, 'MESSAGE' => Array($arData), 'ERROR' => ''));
				if (!defined('BX_UTF') || !BX_UTF)
					$message = $GLOBALS['APPLICATION']->ConvertCharset($message, SITE_CHARSET,'utf-8');

				$result = CPullChannel::Send($channelId, str_replace("\n", " ", $message));
			}
			else
			{
				$arParams = Array(
					'CHANNEL_ID' => $channelId,
					'MESSAGE' => str_replace("\n", " ", serialize($arData)),
					'~DATE_CREATE' => $DB->CurrentTimeFunction(),
				);
				$id = IntVal($DB->Add("b_pull_stack", $arParams, Array("MESSAGE")));
				$result = $id? '{"channel": "'.$channelId.'", "id": "'.$id.'"}': false;
			}

			if (isset($arParams['push_text']) && strlen($arParams['push_text'])>0
			&& isset($arParams['push_user']) && intval($arParams['push_user'])>0)
			{
				$CPushManager = new CPushManager();
				$CPushManager->AddQueue(Array(
					'USER_ID' => $arParams['push_user'],
					'MESSAGE' => str_replace("\n", " ", $arParams['push_text']),
					'PARAMS' => $arParams['push_params'],
					'BADGE' => isset($arParams['push_badge'])? intval($arParams['push_badge']): '',
					'TAG' => isset($arParams['push_tag'])? $arParams['push_tag']: '',
					'SUB_TAG' => isset($arParams['push_sub_tag'])? $arParams['push_sub_tag']: '',
					'APP_ID' => isset($arParams['push_app_id'])? $arParams['push_app_id']: '',
				));
			}
			return $result;
		}

		return false;
	}

	public static function AddByUser($userId, $arMessage)
	{
		if (intval($userId) <= 0)
			return false;

		$arChannel = CPullChannel::Get($userId);
		$arMessage['push_user'] = $userId;
		return self::AddByChannel($arChannel['CHANNEL_ID'], $arMessage);
	}

	public static function AddShared($arMessage)
	{
		if (!CPullOptions::GetNginxStatus())
			return false;

		$arChannel = CPullChannel::GetShared();
		return self::AddByChannel($arChannel['CHANNEL_ID'], $arMessage);
	}

	public static function AddBroadcast($arMessage)
	{
		global $DB;

		$strSql = "SELECT CHANNEL_ID FROM b_pull_channel";
		$dbRes = $DB->Query($strSql, false, "File: ".__FILE__."<br>Line: ".__LINE__);
		while ($arRes = $dbRes->Fetch())
		{
			if(!self::AddByChannel($arRes['CHANNEL_ID'], $arMessage))
				break;
		}

		return true;
	}
}
?>