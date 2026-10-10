<?php namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\ProjectInvoicePaymentModel;
use App\Models\ProjectInvoiceModel;

class ProjectInvoicePaymentController extends BaseController
{
    protected ProjectInvoicePaymentModel $payModel;
    protected ProjectInvoiceModel $invModel;

    public function __construct()
    {
        $this->payModel = new ProjectInvoicePaymentModel();
        $this->invModel = new ProjectInvoiceModel();
    }

    private function json($success, $message = '', $data = null, int $code = 200)
    {
        return $this->response->setStatusCode($code)->setJSON([
            'success' => (bool)$success,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/project-invoices/{invoiceId}/payments
     */
    public function index($invoiceId)
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

        $invoice = $this->invModel->find($invoiceId);
        if (!$invoice) return $this->json(false, 'Invoice not found', null, 404);

        $db = db_connect();

        $rows = $db->table('project_invoice_payments p')
            ->select('p.*, u.name as created_by')
            ->join('users u', 'u.id = p.created_by_user_id', 'left')
            ->where('p.invoice_id', $invoiceId)
            ->orderBy('p.payment_date', 'DESC')
            ->orderBy('p.id', 'DESC')
            ->get()->getResultArray();

        $paid = (float)($db->table('project_invoice_payments')->selectSum('amount', 'paid')
            ->where('invoice_id', $invoiceId)->get()->getRow()->paid ?? 0);

        return $this->json(true, 'OK', [
            'payments' => $rows,
            'summary'  => [
                'invoice_total' => (float)($invoice['total'] ?? 0),
                'paid_total'    => $paid,
                'balance'       => max(0, (float)($invoice['total'] ?? 0) - $paid),
            ]
        ]);
    }

    /**
     * POST /api/project-invoices/{invoiceId}/payments
     * Body: { payment_date, amount, method, reference_no, remarks }
     */
    public function create($invoiceId)
{
    $invoiceId = (int) $invoiceId;
    if ($invoiceId <= 0) return $this->json(false, 'Invalid invoice', null, 400);

    // ✅ Get logged user from your auth header (same as me())
    $userId = (int) ($_SERVER['HTTP_X_USER_ID'] ?? 0);
    if ($userId <= 0) {
        return $this->json(false, 'Unauthorized', null, 401);
    }

    $invoice = $this->invModel->find($invoiceId);
    if (!$invoice) return $this->json(false, 'Invoice not found', null, 404);

    $payload = $this->request->getJSON(true);
    if (!is_array($payload)) $payload = $this->request->getPost();
    if (!is_array($payload)) $payload = [];

    $paymentDate = trim((string)($payload['payment_date'] ?? ''));
    $amount      = (float)($payload['amount'] ?? 0);
    $method      = trim((string)($payload['method'] ?? ''));
    $refNo       = trim((string)($payload['reference_no'] ?? ''));
    $remarks     = $payload['remarks'] ?? null;

    if (!preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $paymentDate)) {
        return $this->json(false, 'payment_date must be YYYY-MM-DD', null, 422);
    }
    if ($amount <= 0) {
        return $this->json(false, 'amount must be > 0', null, 422);
    }

    $db = db_connect();

    // ✅ Avoid FK crash: ensure user exists (important in production)
    $userExists = $db->table('users')->select('id')->where('id', $userId)->get()->getRow();
    if (!$userExists) {
        return $this->json(false, 'Unauthorized: user not found', ['user_id' => $userId], 401);
    }

    $db->transStart();

    try {
        // ✅ Insert payment
        $this->payModel->insert([
            'invoice_id'         => $invoiceId,
            'payment_date'       => $paymentDate,
            'amount'             => $amount,
            'method'             => $method !== '' ? $method : null,
            'reference_no'       => $refNo !== '' ? $refNo : null,
            'remarks'            => $remarks,
            'created_by_user_id' => $userId,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // ✅ Recalculate totals
        $paidRow = $db->table('project_invoice_payments')
            ->selectSum('amount', 'paid')
            ->where('invoice_id', $invoiceId)
            ->get()->getRowArray();

        $paid  = (float)($paidRow['paid'] ?? 0);
        $total = (float)($invoice['total'] ?? 0);

        $newStatus = $invoice['status'] ?? 'draft';
        if ($newStatus !== 'void') {
            if ($total > 0 && $paid >= $total) $newStatus = 'paid';
            else if ($paid > 0) $newStatus = 'partial_paid';
            else $newStatus = 'sent';
        }

        $this->invModel->update($invoiceId, [
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->json(false, 'Failed to add payment', null, 500);
        }

        return $this->json(true, 'Payment added', [
            'invoice_id'     => $invoiceId,
            'paid_total'     => $paid,
            'balance'        => max(0, $total - $paid),
            'invoice_status' => $newStatus,
        ]);
    } catch (\Throwable $e) {
        $db->transRollback();
        return $this->json(false, 'Error: ' . $e->getMessage(), null, 500);
    }
}

    /**
     * DELETE /api/project-invoice-payments/{paymentId}
     */
    public function delete($paymentId)
    {
        $paymentId = (int)$paymentId;
        if ($paymentId <= 0) return $this->json(false, 'Invalid payment', null, 400);

        $payment = $this->payModel->find($paymentId);
        if (!$payment) return $this->json(false, 'Payment not found', null, 404);

        $invoiceId = (int)$payment['invoice_id'];
        $invoice = $this->invModel->find($invoiceId);

        $db = db_connect();
        $db->transStart();

        try {
            $this->payModel->delete($paymentId);

            // Recalc invoice status after deletion
            if ($invoice) {
                $paid = (float)($db->table('project_invoice_payments')->selectSum('amount', 'paid')
                    ->where('invoice_id', $invoiceId)->get()->getRow()->paid ?? 0);

                $total = (float)($invoice['total'] ?? 0);
                $status = $invoice['status'] ?? 'draft';

                if ($status !== 'void') {
                    if ($paid >= $total && $total > 0) $status = 'paid';
                    else if ($paid > 0) $status = 'partial_paid';
                    else $status = 'sent';
                }

                $this->invModel->update($invoiceId, [
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->transComplete();
            if (!$db->transStatus()) return $this->json(false, 'Failed to delete payment', null, 500);

            return $this->json(true, 'Payment deleted', ['payment_id' => $paymentId]);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->json(false, 'Error: ' . $e->getMessage(), null, 500);
        }
    }
}
