<?php



final class PaymentEntity
{

    const entity = "payment";

    const status_created = 'created';
    const status_authorized = 'authorized';
    const status_late_authorized = 'late_authorized';
    const status_pending = 'pending';
    const status_captured = 'captured';
    const status_failed = 'failed';
    const status_cancelled = 'cancelled';
    const status_refunded = 'refunded';
    const status_partially_refunded = 'partially_refunded';

    const status_internally_refunded = 'internally_refunded';
    const status_internally_partially_refunded = 'internally_partially_refunded';



}
