<?php

namespace App\Filament\Resources;

use App\Enums\TradeStatus;
use App\Filament\Resources\TradeResource\Pages;
use App\Models\Trade;
use App\Services\Trades\TradePnlService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TradeResource extends Resource
{
    protected static ?string $model = Trade::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id'),
                Tables\Columns\TextColumn::make('symbol_code'),
                Tables\Columns\TextColumn::make('timeframe_code'),
                Tables\Columns\TextColumn::make('side'),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('opened_at')->dateTime(),
                Tables\Columns\TextColumn::make('closed_at')->dateTime(),
                Tables\Columns\TextColumn::make('entry_price'),
                Tables\Columns\TextColumn::make('exit_price'),
                Tables\Columns\TextColumn::make('realized_points'),
                Tables\Columns\TextColumn::make('unrealized_points'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(TradeStatus::class)
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Action::make('updatePnl')
                    ->label('Update PnL')
                    ->form([
                        TextInput::make('current_price')
                            ->required()
                            ->numeric(),
                    ])
                    ->action(function (array $data) {
                        $openTrades = Trade::where('status', TradeStatus::OPEN)->get();
                        $tradePnlService = app(TradePnlService::class);

                        foreach ($openTrades as $trade) {
                            $tradePnlService->updateUnrealized($trade, $data['current_price']);
                        }

                        Notification::make()
                            ->title('PnL Updated')
                            ->body("Updated PnL for {$openTrades->count()} open trades.")
                            ->success()
                            ->send();
                    }),
            ])
            ->recordClasses(fn (Trade $record) => $record->status === TradeStatus::CLOSED ? 'opacity-50' : null);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrades::route('/'),
        ];
    }
}
