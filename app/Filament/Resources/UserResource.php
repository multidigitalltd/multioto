<?php

namespace App\Filament\Resources;

use App\Enums\TwoFactorChannel;
use App\Enums\UserRole;
use App\Filament\Clusters\Settings;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class UserResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'משתמשי צוות';

    protected static ?string $modelLabel = 'משתמש';

    protected static ?string $pluralModelLabel = 'משתמשי צוות';

    protected static ?string $cluster = Settings::class;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('פרטי המשתמש')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('שם')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('אימייל')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('password')
                            ->label('סיסמה')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->required(fn (string $operation) => $operation === 'create')
                            ->minLength(8)
                            ->helperText('בעריכה — השאירו ריק כדי לא לשנות'),
                        Forms\Components\Select::make('role')
                            ->label('הרשאה')
                            ->options(UserRole::class)
                            ->default(UserRole::Agent)
                            ->required()
                            ->native(false)
                            ->helperText('מנהל — גישה מלאה כולל הגדרות וניהול צוות. נציג — תפעול יומיומי בלבד.')
                            // Never allow the last admin to be demoted — that would
                            // lock everyone out of settings and team management.
                            ->rule(fn (?User $record): \Closure => function (string $attribute, $value, \Closure $fail) use ($record): void {
                                if ($record?->isAdmin() && $value !== UserRole::Admin->value
                                    && User::where('role', UserRole::Admin)->whereKeyNot($record->getKey())->doesntExist()) {
                                    $fail('חייב להישאר לפחות מנהל אחד במערכת.');
                                }
                            }),
                        Forms\Components\TextInput::make('phone')
                            ->label('טלפון')
                            ->tel()
                            ->maxLength(30)
                            ->helperText('נדרש לקבלת קוד כניסה חד-פעמי בוואטסאפ.'),
                    ])->columns(2),

                Forms\Components\Section::make('אימות דו-שלבי (2FA)')
                    ->description('בכניסה, לאחר הסיסמה, יישלח קוד חד-פעמי שיש להזין כדי להיכנס.')
                    ->schema([
                        Forms\Components\Toggle::make('two_factor_enabled')
                            ->label('דרוש קוד כניסה חד-פעמי')
                            ->live(),
                        Forms\Components\Select::make('two_factor_channel')
                            ->label('ערוץ שליחת הקוד')
                            ->options(TwoFactorChannel::class)
                            ->default(TwoFactorChannel::Email)
                            ->native(false)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('two_factor_enabled'))
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('two_factor_enabled'))
                            ->helperText('לוואטסאפ — יש למלא מספר טלפון בפרטי המשתמש.'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('שם')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('email')
                    ->label('אימייל')
                    ->searchable()
                    ->icon('heroicon-m-envelope'),
                Tables\Columns\TextColumn::make('role')
                    ->label('הרשאה')
                    ->badge(),
                Tables\Columns\IconColumn::make('two_factor_enabled')
                    ->label('2FA')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('נוצר')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->label('עריכה'),
                Tables\Actions\Action::make('sendPasswordReset')
                    ->label('שלח איפוס סיסמה')
                    ->icon('heroicon-o-key')
                    ->action(function (User $record): void {
                        try {
                            Password::sendResetLink(['email' => $record->email]);

                            Notification::make()
                                ->title('נשלח מייל לאיפוס סיסמה')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('שליחת המייל נכשלה — ודאו שהמייל מוגדר')
                                ->warning()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('מחיקה')
                        // Never let a member delete their own account in a batch —
                        // this keeps at least one admin (you) in the system.
                        ->before(function (Tables\Actions\DeleteBulkAction $action, Collection $records): void {
                            if ($records->contains(fn (User $user): bool => $user->is(auth()->user()))) {
                                Notification::make()->title('אי אפשר למחוק את המשתמש שלך')->danger()->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('אין משתמשים');
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
