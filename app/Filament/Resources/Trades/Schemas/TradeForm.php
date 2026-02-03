<?php

namespace App\Filament\Resources\Trades\Schemas;

use Filament\Schemas\Schema;
use App\Enums\TradeStatus;

class TradeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\TextInput::make('symbol_code')
                    ->label('Symbol Code')
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('timeframe_code')
                    ->label('Timeframe Code')
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('side')
                    ->label('Side')
                    ->options(['buy' => 'buy', 'sell' => 'sell'])
                    ->required(),
                \Filament\Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(TradeStatus::options())
                    ->required(),
                \Filament\Forms\Components\DateTimePicker::make('opened_at')
                    ->label('Opened At')
                    ->required(),
                \Filament\Forms\Components\DateTimePicker::make('closed_at')
                    ->label('Closed At')
                    ->nullable(),
                \Filament\Forms\Components\TextInput::make('entry_price')
                    ->label('Entry Price')
                    ->numeric()
                    ->required(),
                \Filament\Forms\Components\TextInput::make('exit_price')
                    ->label('Exit Price')
                    ->numeric()
                    ->nullable(),
                \Filament\Forms\Components\TextInput::make('realized_points')
                    ->label('Realized Points')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false),
                \Filament\Forms\Components\TextInput::make('unrealized_points')
                    ->label('Unrealized Points')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false),
                \Filament\Forms\Components\KeyValue::make('meta')
                    ->label('Meta')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
