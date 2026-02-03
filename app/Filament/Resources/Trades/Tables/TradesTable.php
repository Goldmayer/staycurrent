<?php

namespace App\Filament\Resources\Trades\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;
use App\Enums\TradeStatus;

class TradesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('symbol_code')
                    ->label('Symbol Code')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('timeframe_code')
                    ->label('Timeframe Code')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('side')
                    ->label('Side')
                    ->badge()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('opened_at')
                    ->label('Opened At')
                    ->dateTime()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('closed_at')
                    ->label('Closed At')
                    ->dateTime()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('entry_price')
                    ->label('Entry Price')
                    ->numeric()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('exit_price')
                    ->label('Exit Price')
                    ->numeric()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('realized_points')
                    ->label('Realized Points')
                    ->numeric()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('unrealized_points')
                    ->label('Unrealized Points')
                    ->numeric()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(TradeStatus::options()),
                \Filament\Tables\Filters\SelectFilter::make('side')
                    ->label('Side')
                    ->options(['buy' => 'buy', 'sell' => 'sell']),
                \Filament\Tables\Filters\SelectFilter::make('symbol_code')
                    ->label('Symbol Code')
                    ->options(\App\Models\Trade::query()
                        ->select('symbol_code')
                        ->distinct()
                        ->orderBy('symbol_code')
                        ->pluck('symbol_code', 'symbol_code')
                        ->toArray()),
                \Filament\Tables\Filters\SelectFilter::make('timeframe_code')
                    ->label('Timeframe Code')
                    ->options(\App\Models\Trade::query()
                        ->select('timeframe_code')
                        ->distinct()
                        ->orderBy('timeframe_code')
                        ->pluck('timeframe_code', 'timeframe_code')
                        ->toArray()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
