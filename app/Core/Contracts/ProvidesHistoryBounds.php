<?php

namespace App\Core\Contracts;

use DateTimeImmutable;

/**
 * Опциональный интерфейс для адаптеров с фиксированным (не «до сегодня»)
 * концом истории данных, например MockAdapter — генерирует данные только
 * до детерминированной даты ради воспроизводимости прогонов. Не входит в
 * DataSourceAdapter: реальный источник (Bitrix24/1С) не обязан знать конец
 * своей истории — у него это просто «сейчас».
 */
interface ProvidesHistoryBounds
{
    public function historyEnd(): DateTimeImmutable;
}
