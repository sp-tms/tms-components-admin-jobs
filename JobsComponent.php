<?php

namespace Apps\Tms\Components\Jobs;

use Apps\Tms\Packages\Adminltetags\Traits\DynamicTable;
use Apps\Tms\Packages\Companies\Companies;
use Apps\Tms\Packages\Jobs\Charges\JobsCharges;
use Apps\Tms\Packages\Jobs\Invoices\JobsInvoices;
use Apps\Tms\Packages\Jobs\Lrs\JobsLrs;
use Apps\Tms\Packages\Tools\Charges\ToolsCharges;
use Apps\Tms\Packages\Tools\Uom\ToolsUom;
use Apps\Tms\Packages\Vehicles\Vehicles;
use System\Base\BaseComponent;

class JobsComponent extends BaseComponent
{
    use DynamicTable;

    protected $jobsLrsPackage;

    protected $companiesPackage;

    protected $vehiclesPackage;

    protected $toolsUomPackage;

    protected $toolsChargesPackage;

    protected $jobsChargesPackage;

    protected $jobsInvoicesPackage;

    public function initialize()
    {
        $this->checkCurrentFinancialYearFilter();

        $this->jobsLrsPackage = $this->usePackage(JobsLrs::class);

        $this->companiesPackage = $this->usePackage(Companies::class);

        $this->vehiclesPackage = $this->usePackage(Vehicles::class);

        $this->toolsUomPackage = $this->usePackage(ToolsUom::class);

        $this->toolsChargesPackage = $this->usePackage(ToolsCharges::class);

        $this->jobsChargesPackage = $this->usePackage(JobsCharges::class);

        $this->jobsInvoicesPackage = $this->usePackage(JobsInvoices::class);
    }

    /**
     * @acl(name=view)
     */
    public function viewAction()
    {
        if (isset($this->getData()['id'])) {
            $this->useStorage('private');

            $this->view->currencySymbol = '&#8377;';

            $consignors = [];
            $consignees = [];
            $this->view->consignor = [];
            $this->view->consignee = [];
            $this->view->consignorAddresses = [];
            $this->view->consigneeAddresses = [];
            $this->view->uoms = [];
            $this->view->charges = [];
            $this->view->job = [];

            if ($this->getData()['id'] != 0) {
                $job = $this->jobsLrsPackage->getLr((int) $this->getData()['id']);

                if (!$job) {
                    return $this->throwIdNotFound();
                }

                $consignor = $this->companiesPackage->getCompany($job['from_company_id']);

                if ($consignor && isset($consignor['addresses'])) {
                    foreach ($consignor['addresses'] as $consignorAddress) {
                        $consignors[$consignorAddress['id']] = $consignorAddress;
                    }

                    $this->view->consignorAddresses = $consignors;
                }

                $this->view->consignor = $consignor;

                $consignee = $this->companiesPackage->getCompany($job['to_company_id']);

                if ($consignee && isset($consignee['addresses'])) {
                    foreach ($consignee['addresses'] as $consigneeAddress) {
                        $consignees[$consigneeAddress['id']] = $consigneeAddress;
                    }

                    $this->view->consigneeAddresses = $consignees;
                }

                $this->view->consignee = $consignee;
            } else {
                $job = $this->jobsLrsPackage->getNextLr();
            }

            if (isset($job['charges']) && count($job['charges']) > 0) {
                foreach ($job['charges'] as $key => $jobCharge) {
                    $job['charges'][$jobCharge['id']] = $jobCharge;
                    unset($job['charges'][$key]);
                }
            }

            //Get organisation information for invoice details
            if (isset($job['organisation_id']) && $job['organisation_id'] !== 0) {
                $job['organisation'] = $this->companiesPackage->getCompany($job['organisation_id']);
            }
            if (isset($job['company_id']) && $job['company_id'] !== 0) {
                $job['company'] = $this->companiesPackage->getCompany($job['company_id']);
            }

            $this->view->job = $job;

            $this->view->vehicles = $this->vehiclesPackage->getAll()->vehicles;

            $organisations = $this->companiesPackage->getCompaniesByBusinessType();
            if ($organisations && count($organisations) > 0) {
                foreach ($organisations as &$organisation) {
                    $organisation['name'] = $organisation['name'] . ' (' . $organisation['id'] . ')';
                }
            }
            $this->view->organisations = $organisations;

            $customers = $this->companiesPackage->getCompaniesByBusinessType('customers');
            if ($customers && count($customers) > 0) {
                foreach ($customers as &$customer) {
                    $customer['name'] = $customer['name'] . ' (' . $customer['id'] . ')';
                }
            }
            $this->view->customers = $customers;

            $this->view->lrStatus = $this->jobsLrsPackage->getLrAvailableStatus();

            $this->view->uoms = $this->toolsUomPackage->getAll()->toolsuom;

            $charges = $this->toolsChargesPackage->getAll()->toolscharges;

            if (count($charges) > 0) {
                $charges = msort(array: $charges, key: 'type', preserveKey: true);

                foreach ($charges as &$charge) {
                    if ($charge['type'] == 0) {
                        $charge['display_name'] = 'PRODUCT: ' . $charge['name'];
                    } else {
                        $charge['display_name'] = 'CHARGES: ' . $charge['name'];
                    }
                }
            }

            $this->view->charges = $charges;

            $this->view->pick('jobs/view');

            return;
        }

        $controlActions =
            [
                'actionsToEnable'       =>
                [
                    'edit'      => 'jobs'
                ]
            ];

        $replaceColumns =
            function ($dataArr) {
                if ($dataArr && is_array($dataArr) && count($dataArr) > 0) {
                    foreach ($dataArr as &$data) {
                        if ($data['organisation_id'] > 0) {
                            $organisation = $this->companiesPackage->getById($data['organisation_id']);

                            if ($organisation) {
                                $data['organisation_id'] = $organisation['name'] . ' (' . $data['organisation_id'] . ')';
                            }
                        }
                        if ($data['company_id'] > 0) {
                            $customer = $this->companiesPackage->getById($data['company_id']);

                            if ($customer) {
                                $data['company_id'] = $customer['name'] . ' (' . $data['company_id'] . ')';
                            }
                        }
                        if ($data['status'] == '0') {
                            $data['status'] = 'OPEN (' . $data['status'] . ')';
                        } else if ($data['status'] == '1') {
                            $data['status'] = 'COMPLETE (' . $data['status'] . ')';
                        } else if ($data['status'] == '2') {
                            $data['status'] = 'ON TRIP (' . $data['status'] . ')';
                        } else if ($data['status'] == '3') {
                            $data['status'] = 'PAYMENT PENDING (' . $data['status'] . ')';
                        }

                        if (!isset($data['invoice_no'])) {
                            $data['invoice_no'] = '-';
                        }
                    }
                }

                return $dataArr;
            };

        $conditions = [];
        $conditions['order'] = 'date desc';

        $this->generateDTContent(
            $this->jobsLrsPackage,
            'jobs/view',
            $conditions,
            ['id', 'organisation_id', 'company_id', 'financial_year', 'invoice_no', 'date', 'status'],
            true,
            ['id', 'organisation_id', 'company_id', 'financial_year', 'invoice_no', 'date', 'status'],
            $controlActions,
            ['id' => 'LR #', 'organisation_id' => 'Organisation (id)', 'company_id' => 'customer (id)'],
            $replaceColumns,
            'id'
        );

        $this->view->pick('jobs/list');
    }

    /**
     * @acl(name=add)
     */
    public function addAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->addLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode
        );
    }

    /**
     * @acl(name=update)
     */
    public function updateAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->updateLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode
        );
    }

    /**
     * @acl(name=remove)
     */
    public function removeAction()
    {
        //
    }

    protected function checkCurrentFinancialYearFilter()
    {
        $component = $this->modules->components->getComponentByClass($this::class);

        if ($component) {
            $filters = $this->basepackages->filters->getFilters($component['id']);

            if ($filters && count($filters) > 0) {
                foreach ($filters as $filter) {
                    if (strtolower($filter['name']) === 'current financial year') {
                        $now = \Carbon\Carbon::now();

                        if ($now->month < 4) {
                            $nowStartYear = substr($now->clone()->subYear(1)->year, 2);
                            $nowEndYear = substr($now->year, 2);
                        } else {
                            $nowStartYear = substr($now->year, 2);
                            $nowEndYear = substr($now->clone()->addYear(1)->year, 2);
                        }

                        if (str_contains($filter['conditions'], $nowStartYear . '-' . $nowEndYear)) {
                            return;
                        }

                        $conditionsArr = explode('|', $filter['conditions']);

                        $conditionsArr[3] = $nowStartYear . '-' . $nowEndYear . '&';

                        $filter['conditions'] = implode('|', $conditionsArr);

                        $this->basepackages->filters->updateFilter($filter);

                        return true;
                    }
                }
            }
        }
    }

    public function getNextLrAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->getNextLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode,
            $this->jobsLrsPackage->packagesData->responseData ?? []
        );
    }

    public function checkLrAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->checkLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode,
            $this->jobsLrsPackage->packagesData->responseData ?? []
        );
    }

    public function getFyAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->getNextLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode,
            $this->jobsLrsPackage->packagesData->responseData ?? []
        );
    }

    public function updateDocumentAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->updateDocument($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode,
            $this->jobsLrsPackage->packagesData->responseData ?? []
        );
    }

    public function checkInvoiceAction()
    {
        $this->requestIsPost();

        $this->jobsInvoicesPackage->checkInvoice($this->postData());

        $this->addResponse(
            $this->jobsInvoicesPackage->packagesData->responseMessage,
            $this->jobsInvoicesPackage->packagesData->responseCode,
            $this->jobsInvoicesPackage->packagesData->responseData ?? []
        );
    }

    public function getNextInvoiceNumberAction()
    {
        $this->requestIsPost();

        $this->jobsInvoicesPackage->getNextInvoiceNumber($this->postData()['financial_year']);

        $this->addResponse(
            $this->jobsInvoicesPackage->packagesData->responseMessage,
            $this->jobsInvoicesPackage->packagesData->responseCode,
            $this->jobsInvoicesPackage->packagesData->responseData ?? []
        );
    }

    public function extractDigitalSignatureAction()
    {
        $this->requestIsPost();

        $this->jobsInvoicesPackage->extractDigitalSignature($this->postData()['uuid']);

        $this->addResponse(
            $this->jobsInvoicesPackage->packagesData->responseMessage,
            $this->jobsInvoicesPackage->packagesData->responseCode,
            $this->jobsInvoicesPackage->packagesData->responseData ?? []
        );
    }

    public function signInvoiceAction()
    {
        $this->requestIsPost();

        $this->jobsInvoicesPackage->signInvoice($this->postData());

        $this->addResponse(
            $this->jobsInvoicesPackage->packagesData->responseMessage,
            $this->jobsInvoicesPackage->packagesData->responseCode,
            $this->jobsInvoicesPackage->packagesData->responseData ?? []
        );
    }

    public function addJobsChargeAction()
    {
        $this->requestIsPost();

        $this->jobsChargesPackage->addJobsCharge($this->postData());

        $this->addResponse(
            $this->jobsChargesPackage->packagesData->responseMessage,
            $this->jobsChargesPackage->packagesData->responseCode,
            $this->jobsChargesPackage->packagesData->responseData ?? []
        );
    }

    public function updateJobsChargeAction()
    {
        $this->requestIsPost();

        $this->jobsChargesPackage->updateJobsCharge($this->postData());

        $this->addResponse(
            $this->jobsChargesPackage->packagesData->responseMessage,
            $this->jobsChargesPackage->packagesData->responseCode,
            $this->jobsChargesPackage->packagesData->responseData ?? []
        );
    }

    public function removeJobsChargeAction()
    {
        $this->requestIsPost();

        $this->jobsChargesPackage->removeJobsCharge($this->postData());

        $this->addResponse(
            $this->jobsChargesPackage->packagesData->responseMessage,
            $this->jobsChargesPackage->packagesData->responseCode,
            $this->jobsChargesPackage->packagesData->responseData ?? []
        );
    }

    public function getLrPreviewAction()
    {
        $this->getQueryArr['id'] = $this->postData()['lr_no'];

        $this->viewAction();

        $this->getQueryArr['id'] = null;//Avoid CSRF Token Regeneration

        return $this->view->getPartial('form/preview', ['preview' => 'lr']);
    }

    public function getInvoicePreviewAction()
    {
        $this->getQueryArr['id'] = $this->postData()['lr_no'];

        $this->viewAction();

        $this->getQueryArr['id'] = null;//Avoid CSRF Token Regeneration

        return $this->view->getPartial('form/preview', ['preview' => 'invoice']);
    }
}