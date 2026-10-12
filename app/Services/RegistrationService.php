<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Repositories\WalletRepository;
use Throwable;

final class RegistrationService
{
    public function __construct(
        private readonly UserRepository         $users   = new UserRepository(),
        private readonly WalletRepository       $wallets = new WalletRepository(),
        private readonly NotificationRepository $notify  = new NotificationRepository(),
        private readonly AuditService           $audit   = new AuditService(),
    ) {}

    /**
     * @return array{ok:bool,user_id?:int,user?:array,errors?:array}
     */
    public function register(array $input): array
    {
        $data = [
            'full_name'             => trim((string) ($input['full_name'] ?? '')),
            'email'                 => strtolower(trim((string) ($input['email'] ?? ''))),
            'phone'                 => trim((string) ($input['phone'] ?? '')),
            'password'              => (string) ($input['password'] ?? ''),
            'password_confirmation' => (string) ($input['password_confirmation'] ?? ''),
            'address'               => trim((string) ($input['address'] ?? '')),
            'state'                 => trim((string) ($input['state'] ?? '')),
        ];

        $v = (new Validator($data))
            ->required('full_name', 'Full name')
            ->min('full_name', 3, 'Full name')
            ->max('full_name', 150, 'Full name')
            ->required('email', 'Email')
            ->email('email', 'Email')
             ->required('password', 'Password')
            ->min('password', 8, 'Password')
            ->matches('password_confirmation', 'password', 'Password confirmation');

        if ($v->fails()) {
            return ['ok' => false, 'errors' => $v->errors()];
        }

        if ($this->users->emailExists($data['email'])) {
            return ['ok' => false, 'errors' => ['email' => 'This email address is already registered.']];
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);

        try {
            $userId = Database::transaction(function () use ($data, $hash): int {
                $userId = $this->users->create([
                    'full_name'     => $data['full_name'],
                    'email'         => $data['email'],
                    'phone'         => $data['phone'],
                    'password_hash' => $hash,
                    'address'       => $data['address'] ?: null,
                    'state'         => $data['state']   ?: null,
                ]);

                $walletId = $this->wallets->createForUser($userId);

                $this->audit->log(
                    'REGISTER',
                    'user',
                    $userId,
                    'Client registered; wallet created',
                    newValues: ['email' => $data['email']]
                );
                $this->audit->log(
                    'WALLET_CREATED',
                    'wallet',
                    $walletId,
                    'Wallet created for user ' . $userId
                );

                $this->notify->create(
                    'client',
                    $userId,
                    'Welcome to ' . APP_NAME,
                    'Your account is ready. Fund your wallet to start using our services.',
                    'system',
                    '/client/wallet'
                );

                return $userId;
            });

            return ['ok' => true, 'user_id' => $userId, 'user' => $this->users->findById($userId)];
        } catch (Throwable $e) {
            Logger::error('Registration failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'errors' => ['general' => 'Registration failed. Please try again.']];
        }
    }
}