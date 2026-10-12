<?php
declare(strict_types=1);

// User statuses
const STATUS_ACTIVE    = 'active';
const STATUS_INACTIVE  = 'inactive';
const STATUS_SUSPENDED = 'suspended';

// Submission statuses
const SUB_PENDING      = 'pending';
const SUB_PROCESSING   = 'processing';
const SUB_COMPLETED    = 'completed';
const SUB_ERROR        = 'error_detected';
const SUB_CANCELLED    = 'cancelled';

// Wallet transaction types
const WALLET_CREDIT = 'credit';
const WALLET_DEBIT  = 'debit';

const WALLET_CAT_FUNDING        = 'funding';
const WALLET_CAT_SERVICE_CHARGE = 'service_charge';
const WALLET_CAT_REFUND         = 'refund';
const WALLET_CAT_REVERSAL       = 'reversal';
const WALLET_CAT_ADJUSTMENT     = 'adjustment';

// Payment statuses
const PAYMENT_PENDING                = 'pending';
const PAYMENT_AWAITING_VERIFICATION  = 'awaiting_verification';
const PAYMENT_SUCCESSFUL             = 'successful';
const PAYMENT_FAILED                 = 'failed';
const PAYMENT_REJECTED               = 'rejected';
const PAYMENT_CANCELLED              = 'cancelled';