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

        $organisations = $this->companiesPackage->getCompaniesByBusinessType();
        if ($organisations && count($organisations) > 0) {
            foreach ($organisations as &$organisation) {
                $organisation['name'] = $organisation['name'] . ' (' . $organisation['id'] . ')';
            }
        }

        $this->setModuleSettingsData(['organisations' => $organisations]);

        $this->vehiclesPackage = $this->usePackage(Vehicles::class);

        $this->toolsUomPackage = $this->usePackage(ToolsUom::class);

        $this->toolsChargesPackage = $this->usePackage(ToolsCharges::class);

        $this->jobsChargesPackage = $this->usePackage(JobsCharges::class);

        $this->jobsInvoicesPackage = $this->usePackage(JobsInvoices::class);

        $this->setModuleSettings();
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
                $this->jobsLrsPackage->useMutex(true);
                $this->companiesPackage->useMutex(true);

                $job = $this->jobsLrsPackage->getLr((int) $this->getData()['id']);

                if (!$job) {
                    return $this->throwIdNotFound();
                }

                $this->setActivityLogsPackage($this->jobsLrsPackage, 'jobs/view');

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

            //Charges
            if (isset($job['charges']) && count($job['charges']) > 0) {
                foreach ($job['charges'] as $key => $jobCharge) {
                    $job['charges'][$jobCharge['id']] = $jobCharge;

                    unset($job['charges'][$key]);
                }
            }

            //Get organisation and company information for invoice details
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

            $this->setModuleSettingsData($organisations);

            //Get All Customers
            $customers = $this->companiesPackage->getCompaniesByBusinessType('customers');
            if ($customers && count($customers) > 0) {
                foreach ($customers as &$customer) {
                    $customer['name'] = $customer['name'] . ' (' . $customer['id'] . ')';
                }
            }
            $this->view->customers = $customers;

            //Available LR Status
            $this->view->lrStatus = $this->jobsLrsPackage->getLrAvailableStatus();

            //Available UoMS
            $this->view->uoms = $this->toolsUomPackage->getAll()->toolsuom;

            //Available Charges
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

            $this->view->formattedInvoice = '';
            //Print options
            if (isset($this->getData()['print'])) {
                $this->view->print = $this->getData()['print'];

                if ($this->view->print === 'lr') {
                    $package = $this->modules->packages->getPackageByClass(get_class($this->jobsLrsPackage));
                } else if ($this->view->print === 'invoice') {
                    $package = $this->modules->packages->getPackageByClass(get_class($this->jobsInvoicesPackage));
                } else {
                    return $this->throwIdNotFound();
                }

                if (!$package) {
                    return $this->throwIdNotFound();
                }

                $organisation_settings = [];
                if (isset($package['settings']['organisations'][$job['organisation_id']])) {
                    $organisation_settings = $package['settings']['organisations'][$job['organisation_id']];
                }

                $this->view->organisationSettings = $organisation_settings;

                $this->view->formattedInvoice = $this->jobsLrsPackage->getFormattedInvoice($job, $organisation_settings);

                $invoiceDataArr = [];

                $this->getTotal($invoiceDataArr);

                if (count($invoiceDataArr) > 0) {
                    foreach ($invoiceDataArr as $invoiceDataKey => $invoiceData) {
                        $this->view->{$invoiceDataKey} = $invoiceData;
                    }
                }

                $this->view->setLayout('print');

                $this->view->pick('jobs/form/print');

                $this->view->showLrNotes = false;
                $this->view->showINotes = false;
                $this->view->showOpterms = false;
                $this->view->showCpterms = false;

                if (isset($this->getData()['lrnotes']) && $this->getData()['lrnotes'] == 'true') {
                    $this->view->showLrNotes = true;
                }
                if (isset($this->getData()['inotes']) && $this->getData()['inotes'] == 'true') {
                    $this->view->showINotes = true;
                }
                if (isset($this->getData()['opterms']) && $this->getData()['opterms'] == 'true') {
                    $this->view->showOpterms = true;
                }
                if (isset($this->getData()['cpterms']) && $this->getData()['cpterms'] == 'true') {
                    $this->view->showCpterms = true;
                }

                $this->getQueryArr['id'] = null;//Avoid CSRF Token Regeneration

                return;
            } else {//Get Invoice settings
                if (isset($job['organisation_id'])) {
                    $package = $this->modules->packages->getPackageByClass(get_class($this->jobsInvoicesPackage));

                    if (!$package) {
                        return $this->throwIdNotFound();
                    }

                    $organisation_settings = [];
                    if (isset($package['settings']['organisations'][$job['organisation_id']])) {
                        $organisation_settings = $package['settings']['organisations'][$job['organisation_id']];
                    }

                    $this->view->organisationSettings = $organisation_settings;

                    $this->view->formattedInvoice = $this->jobsLrsPackage->getFormattedInvoice($job, $organisation_settings);
                }
            }

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
        $conditions['order'] = 'id desc';

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
     * @notification(name=add)
     */
    public function addAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->addLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode
        );

        if ($this->jobsLrsPackage->packagesData->responseCode === 0) {
            $this->addToNotification('add', 'Added new job ' . $this->jobsLrsPackage->packagesData->last['name'], null, $this->jobsLrsPackage->packagesData->last);
        }
    }

    /**
     * @acl(name=update)
     * @notification(name=update)
     */
    public function updateAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->useMutex(true);

        $this->jobsLrsPackage->updateLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode
        );

        if ($this->jobsLrsPackage->packagesData->responseCode === 0) {
            $this->addToNotification('update', 'Updated company ' . $this->jobsLrsPackage->packagesData->last['name'], null, $this->jobsLrsPackage->packagesData->last);
        }
    }

    /**
     * @acl(name=remove)
     * @notification(name=remove)
     */
    public function removeAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->removeLr($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode
        );

        if ($this->jobsLrsPackage->packagesData->responseCode === 0) {
            $this->addToNotification('remove', 'Archived job ' . $this->jobsLrsPackage->packagesData->last['name'], null, $this->jobsLrsPackage->packagesData->last);
        }
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

    public function emailAction()
    {
        $this->requestIsPost();

        $this->jobsLrsPackage->email($this->postData());

        $this->addResponse(
            $this->jobsLrsPackage->packagesData->responseMessage,
            $this->jobsLrsPackage->packagesData->responseCode,
            $this->jobsLrsPackage->packagesData->responseData ?? []
        );
    }

    public function getTotal(&$invoiceDataArr = [])
    {
        $total = 0;
        if ($this->view->job['charges'] && count($this->view->job['charges']) > 0) {
            $charges = [];
            foreach ($this->view->job['charges'] as $chargeKey => $charge) {
                $total += $charge['amount'];

                $charge['amount'] =
                    str_replace('EN_ ', '', (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))->formatCurrency($charge['amount'], 'en_IN'));
                $charge['quantity'] =
                    str_replace('EN_ ', '', (new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL))->format($charge['quantity']));
                $charge['rate'] =
                    str_replace('EN_ ', '', (new \NumberFormatter('en_IN', \NumberFormatter::DECIMAL))->format($charge['rate']));

                $charges[$chargeKey] = $charge;
            }
            $job = $this->view->job;
            $job['charges'] = $charges;
            $this->view->job = $job;
        }


        $rupees = floor($total);
        $paise = round($total - $rupees, 2) * 100;
        $totalInWords = '';

        if ($total > 0) {
            $formatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
            $totalInWords .= '&#8360; ' . $formatter->format($rupees);

            if ($paise > 0) {
                $totalInWords .= ' and ' . $formatter->format($paise) . ' paise';
            }

            $formatter = new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY);
            $total = str_replace('EN_ ', '', (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))->formatCurrency($total, 'en_IN'));
        }

        $invoiceDataArr['total'] = $total;
        $invoiceDataArr['totalInWords'] = ucwords($totalInWords) . ' Only';
    }
}