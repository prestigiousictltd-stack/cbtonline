<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\ServiceRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Repositories\WalletRepository;
use App\Support\DynamicForm;
use RuntimeException;
use Throwable;

final class SubmissionService
{
    public function __construct(
        private readonly ServiceRepository                     $services     = new ServiceRepository(),
        private readonly SubmissionRepository                  $submissions  = new SubmissionRepository(),
        private readonly SubmissionFieldValueRepository        $fieldValues  = new SubmissionFieldValueRepository(),
        private readonly SubmissionStatusHistoryRepository     $statusHistory = new SubmissionStatusHistoryRepository(),
        private readonly WalletRepository                      $wallets      = new WalletRepository(),
        private readonly TrackingIdService                     $tracking     = new TrackingIdService(),
        private readonly WalletService                         $walletSvc    = new WalletService(),
        private readonly NotificationService                   $notify       = new NotificationService(),
        private readonly AuditService                          $audit        = new AuditService(),
    ) {}

    /**
     * Perform the full atomic submission:
     *   validate → snapshot pricing → check wallet → insert → debit → notify.
     *
     * @param  array<string,mixed> $input The raw $_POST (must include ['fields'] for dynamic values)
     * @return array{
     *   ok:bool,
     *   submission_id?:int,
     *   tracking_id?:string,
     *   error?:string,
     *   errors?:array<string,string>,
     *   required?:string,
     *   available?:string
     * }
     */
    public function submit(int $userId, int $serviceId, array $input, ?int $parentSubmissionId = null): array
    {
        try {
            return Database::transaction(function () use ($userId, $serviceId, $input, $parentSubmissionId): array {
                // 1. Load + validate service
                $service = $this->services->findById($serviceId);
                if (!$service) {
                    return ['ok' => false, 'error' => 'Service not found.'];
                }
                if ($service['status'] !== 'active') {
                    return ['ok' => false, 'error' => 'This service is currently unavailable.'];
                }

                // 2. Build and run dynamic form
                $form = DynamicForm::forService($serviceId);
                $fieldsRaw = is_array($input['fields'] ?? null) ? $input['fields'] : [];
                $validated = $form->validate($fieldsRaw);
                if (!$validated['ok']) {
                    return ['ok' => false, 'errors' => $validated['errors']];
                }

                // 3. Snapshot pricing / duration
                $price      = money_normalize((string) $service['price']);
                $commission = money_normalize((string) $service['admin_commission']);
                $minutes    = (int) $service['processing_minutes'];

                // 4. Lock wallet & check balance (this is where FOR UPDATE + row lock happen)
                $wallet = $this->wallets->findByUserId($userId);
                if (!$wallet) throw new RuntimeException('Wallet not found.');
                $locked = $this->wallets->lockForUpdate((int) $wallet['id']);
                if (!$locked) throw new RuntimeException('Wallet lock failed.');
                if ($locked['status'] !== 'active') {
                    return ['ok' => false, 'error' => 'Your wallet is not active. Please contact support.'];
                }
                $balance = money_normalize((string) $locked['balance']);

                if (money_cmp($balance, $price) < 0) {
                    // Do NOT create the submission, do NOT debit the wallet
                    return [
                        'ok'        => false,
                        'error'     => 'INSUFFICIENT_FUNDS',
                        'required'  => $price,
                        'available' => $balance,
                    ];
                }

                // 5. Generate tracking ID (locks the sequence row)
                $trackingId = $this->tracking->generate();

                // 6. Insert submission
                $submissionId = $this->submissions->create([
                    'tracking_id'                  => $trackingId,
                    'user_id'                      => $userId,
                    'service_id'                   => $serviceId,
                    'status'                       => SUB_PENDING,
                    'service_name_snapshot'        => (string) $service['name'],
                    'price_charged'                => $price,
                    'admin_commission_amount'      => $commission,
                    'processing_minutes_snapshot'  => $minutes,
                    'parent_submission_id'         => $parentSubmissionId,
                ]);

                // 7. Store dynamic field values
                foreach ($validated['values'] as $v) {
                    $this->fieldValues->create($submissionId, $v);
                }

                // 8. Status history entry
                $this->statusHistory->record(
                    $submissionId, null, SUB_PENDING, 'client', $userId,
                    'Submission created'
                );

                // 9. Debit wallet + ledger (transaction-scoped — no nested txn)
                $this->walletSvc->debitTx([
                    'user_id'         => $userId,
                    'amount'          => $price,
                    'category'        => WALLET_CAT_SERVICE_CHARGE,
                    'reference_type'  => 'submission',
                    'reference_id'    => $submissionId,
                    'description'     => 'Service charge: ' . $service['name'] . ' (' . $trackingId . ')',
                    'created_by_type' => 'client',
                    'created_by_id'   => $userId,
                ]);

                // 10. Audit
                $this->audit->log(
                    'SUBMISSION_CREATED', 'submission', $submissionId,
                    'Client submitted ' . $service['name'] . ' (' . $trackingId . ')',
                    newValues: [
                        'tracking_id' => $trackingId,
                        'service'     => $service['name'],
                        'price'       => $price,
                    ],
                    userType: 'client', userId: $userId
                );

                // 11. Notify client (outside service method to keep atomic flow simple)
                $this->notify->notifyClient(
                    $userId,
                    'Submission received',
                    'We received your request. Tracking ID: ' . $trackingId . '. You can track it any time.',
                    'submission',
                    '/client/submission-view?id=' . $submissionId
                );

                return [
                    'ok'            => true,
                    'submission_id' => $submissionId,
                    'tracking_id'   => $trackingId,
                ];
            });
        } catch (RuntimeException $e) {
            // INSUFFICIENT_FUNDS is handled before the throw so this is a real error
            Logger::error('Submission failed', ['error' => $e->getMessage(), 'user_id' => $userId, 'service_id' => $serviceId]);
            return ['ok' => false, 'error' => 'Could not complete your submission. Please try again.'];
        } catch (Throwable $e) {
            Logger::error('Submission failed', ['error' => $e->getMessage(), 'user_id' => $userId, 'service_id' => $serviceId]);
            return ['ok' => false, 'error' => 'Could not complete your submission. Please try again.'];
        }
    }
}