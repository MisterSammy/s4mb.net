<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ThemeResource\Pages;
use App\Models\Theme;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ThemeResource extends Resource
{
    protected static ?string $model = Theme::class;

    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        $defaultColors = Theme::defaultColors();

        return $form
            ->schema([
                Forms\Components\Section::make('Theme Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if ($state) {
                                    $set('slug', \Illuminate\Support\Str::slug($state));
                                }
                            }),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ])->columns(2),

                Forms\Components\Section::make('Color Palette')
                    ->description('Define the colors for your theme. Click on each color to use the color picker.')
                    ->schema([
                        Forms\Components\ColorPicker::make('colors.background')
                            ->label('Background')
                            ->helperText('Main page background color')
                            ->default($defaultColors['background'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.surface')
                            ->label('Surface')
                            ->helperText('Secondary background, cards, sections')
                            ->default($defaultColors['surface'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.accent')
                            ->label('Accent')
                            ->helperText('Primary accent color for highlights')
                            ->default($defaultColors['accent'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.secondary_accent')
                            ->label('Secondary Accent')
                            ->helperText('Secondary accent for links and interactions')
                            ->default($defaultColors['secondary_accent'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.text')
                            ->label('Text')
                            ->helperText('Primary text color')
                            ->default($defaultColors['text'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.text_muted')
                            ->label('Text Muted')
                            ->helperText('Secondary/muted text color')
                            ->default($defaultColors['text_muted'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.border')
                            ->label('Border')
                            ->helperText('Border and divider color')
                            ->default($defaultColors['border'])
                            ->required(),
                        Forms\Components\ColorPicker::make('colors.darkest')
                            ->label('Darkest')
                            ->helperText('Darkest color for emphasis')
                            ->default($defaultColors['darkest'])
                            ->required(),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\ColorColumn::make('colors.background')
                    ->label('BG'),
                Tables\Columns\ColorColumn::make('colors.accent')
                    ->label('Accent'),
                Tables\Columns\ColorColumn::make('colors.text')
                    ->label('Text'),
                Tables\Columns\ColorColumn::make('colors.darkest')
                    ->label('Dark'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status'),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Activate Theme')
                    ->modalDescription('This will deactivate any currently active theme and activate this one.')
                    ->action(function (Theme $record) {
                        $record->activate();
                        Cache::forget('active_theme');

                        Notification::make()
                            ->title('Theme Activated')
                            ->body("'{$record->name}' is now the active theme.")
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Theme $record) => ! $record->is_active),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Theme $record) {
                        if ($record->is_active) {
                            Notification::make()
                                ->title('Cannot Delete Active Theme')
                                ->body('Please activate a different theme before deleting this one.')
                                ->danger()
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListThemes::route('/'),
            'create' => Pages\CreateTheme::route('/create'),
            'edit' => Pages\EditTheme::route('/{record}/edit'),
        ];
    }
}
