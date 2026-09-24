<?php

namespace App\Core\Domain;

use App\Core\Domain\Enums\StockMovementType;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Одно движение товара по одному складу. Количество — со знаком
 * (приход +, расход −), поэтому остаток на складе = сумма quantity его
 * движений. Знак задан типом: receipt/transfer_in — положительное;
 * sale/transfer_out/writeoff — отрицательное; adjustment — любое
 * ненулевое.
 *
 * Перемещение между складами — ДВЕ записи: transfer_out на складе-
 * источнике и transfer_in на складе-получателе, равные по модулю
 * количества; общий ключ пары кладётся в meta['transfer_id'] (если
 * источник умеет его отдать).
 *
 * Идентификатор источника — $id, как у Deal/Product (внешний id записи
 * в источнике); $date — момент движения, как у Deal.
 */
final readonly class StockMovement
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $warehouseId,
        /** Со знаком: приход +, расход −. */
        public float $quantity,
        public StockMovementType $type,
        public DateTimeImmutable $date,
        public array $meta = [],
    ) {
        if ($quantity === 0.0) {
            throw new InvalidArgumentException('quantity must not be zero.');
        }

        $mustBePositive = match ($type) {
            StockMovementType::Receipt, StockMovementType::TransferIn => true,
            StockMovementType::Sale, StockMovementType::TransferOut, StockMovementType::Writeoff => false,
            StockMovementType::Adjustment => null,
        };

        if ($mustBePositive !== null && ($quantity > 0) !== $mustBePositive) {
            throw new InvalidArgumentException(sprintf(
                'quantity of type "%s" must be %s, got %s.',
                $type->value,
                $mustBePositive ? 'positive' : 'negative',
                $quantity,
            ));
        }
    }
}
