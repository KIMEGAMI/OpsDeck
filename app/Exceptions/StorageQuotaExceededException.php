<?php

namespace App\Exceptions;

use RuntimeException;

class StorageQuotaExceededException extends RuntimeException
{
    private const SAVED_DATA_NOTICE = 'すでに保存したファイルや登録済みの情報は削除されません。';

    public function __construct(string $message = '保存容量の上限に達したため、このファイルは受け付けできません。プランと現在の保存量を確認してください。')
    {
        parent::__construct(trim($message).' '.self::SAVED_DATA_NOTICE);
    }
}
