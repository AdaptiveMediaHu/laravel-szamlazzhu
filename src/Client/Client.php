<?php

namespace zoparga\SzamlazzHu\Client;

use Carbon\Carbon;
use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SebastianBergmann\CodeCoverage\ParserException;
use XMLWriter;
use zoparga\SzamlazzHu\Client\ApiErrors\AuthenticationException;
use zoparga\SzamlazzHu\Client\ApiErrors\CannotCreateInvoiceException;
use zoparga\SzamlazzHu\Client\ApiErrors\CommonResponseException;
use zoparga\SzamlazzHu\Client\ApiErrors\InvalidGrossPriceValueException;
use zoparga\SzamlazzHu\Client\ApiErrors\InvalidInvoicePrefixException;
use zoparga\SzamlazzHu\Client\ApiErrors\InvalidNetPriceValueException;
use zoparga\SzamlazzHu\Client\ApiErrors\InvalidVatRateValueException;
use zoparga\SzamlazzHu\Client\ApiErrors\InvoiceNotificationSendingException;
use zoparga\SzamlazzHu\Client\ApiErrors\KeystoreOpeningException;
use zoparga\SzamlazzHu\Client\ApiErrors\NoXmlFileException;
use zoparga\SzamlazzHu\Client\ApiErrors\ReceiptAlreadyExistsException;
use zoparga\SzamlazzHu\Client\ApiErrors\ReceiptNotFoundException;
use zoparga\SzamlazzHu\Client\ApiErrors\RemoteMaintenanceException;
use zoparga\SzamlazzHu\Client\ApiErrors\UnsuccessfulInvoiceSignatureException;
use zoparga\SzamlazzHu\Client\ApiErrors\XmlReadingException;
use zoparga\SzamlazzHu\Client\Errors\InvalidClientConfigurationException;
use zoparga\SzamlazzHu\Client\Errors\InvoiceNotFoundException;
use zoparga\SzamlazzHu\Client\Errors\InvoiceValidationException;
use zoparga\SzamlazzHu\Client\Errors\ModelValidationException;
use zoparga\SzamlazzHu\Client\Errors\ReceiptValidationException;
use zoparga\SzamlazzHu\Client\Models\InvoiceCancellationResponse;
use zoparga\SzamlazzHu\Client\Models\InvoiceCreationResponse;
use zoparga\SzamlazzHu\Client\Models\InvoicePreviewResponse;
use zoparga\SzamlazzHu\Client\Models\ProformaInvoiceDeletionResponse;
use zoparga\SzamlazzHu\Client\Models\QueryTaxPayerResponse;
use zoparga\SzamlazzHu\Client\Models\ReceiptCancellationResponse;
use zoparga\SzamlazzHu\Client\Models\ReceiptCreationResponse;
use zoparga\SzamlazzHu\Contracts\ArrayableMerchant;
use zoparga\SzamlazzHu\Internal\AbstractInvoice;
use zoparga\SzamlazzHu\Internal\AbstractModel;
use zoparga\SzamlazzHu\Internal\Support\ClientAccessor;
use zoparga\SzamlazzHu\Internal\Support\CustomerTaxSubjects;
use zoparga\SzamlazzHu\Internal\Support\InvoiceValidationRules;
use zoparga\SzamlazzHu\Internal\Support\MerchantHolder;
use zoparga\SzamlazzHu\Internal\Support\NormalizeParsedNumericArrays;
use zoparga\SzamlazzHu\Internal\Support\PaymentMethods;
use zoparga\SzamlazzHu\Internal\Support\ReceiptValidationRules;
use zoparga\SzamlazzHu\Invoice;
use zoparga\SzamlazzHu\ProformaInvoice;
use zoparga\SzamlazzHu\Receipt;
use zoparga\SzamlazzHu\Util\XmlParser;

class Client
{
    use CustomerTaxSubjects,
        InvoiceValidationRules,
        MerchantHolder,
        NormalizeParsedNumericArrays,
        PaymentMethods,
        ReceiptValidationRules,
        XmlParser;

    public const NON_EU_COMPANY = 7;

    public const EU_COMPANY = 6;

    public const HUNGARIAN_TAX_ID = 1;

    public const UNKNOWN = 0;

    public const NO_TAX_ID = -1;

    /*
     * All the available actions.
     * */
    private const ACTIONS = [

        // Used for cancelling (existing) invoices
        'CANCEL_INVOICE' => [
            'name' => 'action-szamla_agent_st',
            'schema' => [
                /*
                 * Important! Please always note order
                 * */
                'xmlszamlast', // Action name
                'http://www.szamlazz.hu/xmlszamlast', // Namespace
                'http://www.szamlazz.hu/xmlszamlast xmlszamlast.xsd', // Schema location
            ],
        ],

        // Used for deleting (existing) proforma invoices
        'DELETE_PROFORMA_INVOICE' => [
            'name' => 'action-szamla_agent_dijbekero_torlese',
            'schema' => [
                'xmlszamladbkdel',
                'http://www.szamlazz.hu/xmlszamladbkdel',
                'http://www.szamlazz.hu/xmlszamladbkdel http://www.szamlazz.hu/docs/xsds/szamladbkdel/xmlszamladbkdel.xsd',
            ],
        ],

        // Used for obtaining (both) invoices and proforma invoices
        'GET_COMMON_INVOICE' => [
            'name' => 'action-szamla_agent_xml',
            'schema' => [
                'xmlszamlaxml',
                'http://www.szamlazz.hu/xmlszamlaxml',
                'http://www.szamlazz.hu/xmlszamlaxml http://www.szamlazz.hu/docs/xsds/agentpdf/xmlszamlaxml.xsd',
            ],
        ],

        // Used to upload (create) new common and proforma invoice
        'UPLOAD_COMMON_INVOICE' => [
            'name' => 'action-xmlagentxmlfile',
            'schema' => [
                'xmlszamla',
                'http://www.szamlazz.hu/xmlszamla',
                'http://www.szamlazz.hu/xmlszamla http://www.szamlazz.hu/docs/xsds/agent/xmlszamla.xsd',
            ],
        ],

        // Used to create / update receipt
        'UPLOAD_RECEIPT' => [
            'name' => 'action-szamla_agent_nyugta_create',
            'schema' => [
                'xmlnyugtacreate',
                'http://www.szamlazz.hu/xmlnyugtacreate',
                'http://www.szamlazz.hu/xmlnyugtacreate http://www.szamlazz.hu/docs/xsds/nyugta/xmlnyugtacreate.xsd',
            ],
        ],

        // Cancelling receipt
        'CANCEL_RECEIPT' => [
            'name' => 'action-szamla_agent_nyugta_storno',
            'schema' => [
                'xmlnyugtast',
                'http://www.szamlazz.hu/xmlnyugtast',
                'http://www.szamlazz.hu/xmlnyugtast http://www.szamlazz.hu/docs/xsds/nyugtast/xmlnyugtast.xsd',
            ],
        ],

        // Obtaining a single receipt
        'GET_RECEIPT' => [
            'name' => 'action-szamla_agent_nyugta_get',
            'schema' => [
                'xmlnyugtaget',
                'http://www.szamlazz.hu/xmlnyugtaget',
                'http://www.szamlazz.hu/xmlnyugtaget http://www.szamlazz.hu/docs/xsds/nyugtaget/xmlnyugtaget.xsd',
            ],
        ],

        // Querying tax payer validity
        'QUERY_TAX_PAYER' => [
            'name' => 'action-szamla_agent_taxpayer',
            'schema' => [
                'xmltaxpayer',
                'http://www.szamlazz.hu/xmltaxpayer',
                'http://www.szamlazz.hu/xmltaxpayer http://www.szamlazz.hu/docs/xsds/agent/xmltaxpayer.xsd',
            ],
        ],
    ];

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array|null
     */
    protected $defaultMerchant = null;

    /**
     * Default API config
     *
     * @var array
     */
    protected $defaultConfig = [
        'timeout' => 30,
        'base_uri' => 'https://www.szamlazz.hu/',
        'certificate' => [
            'enabled' => false,
        ],
        'storage' => [
            'auto_save' => false,
            'disk' => 'local',
            'path' => 'szamlazzhu',
        ],
    ];

    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Client constructor.
     *
     * @param  array|ArrayableMerchant  $merchant
     *
     * @throws InvalidClientConfigurationException
     */
    public function __construct(array $config, \GuzzleHttp\Client $client, $merchant = null)
    {
        $this->config = array_merge($this->defaultConfig, $config);
        static::validateConfig($this->config);

        if (! empty($merchant)) {
            $this->defaultMerchant = $this->simplifyMerchant($merchant);
        }

        $this->client = $client;
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @throws InvalidClientConfigurationException
     */
    protected static function validateConfig(array $config)
    {
        $rules = [
            'credentials.username' => 'required_without:credentials.api_key',
            'credentials.password' => 'required_without:credentials.api_key',
            'credentials.api_key' => 'required_without:credentials.username',
            'timeout' => ['integer', 'min:10', 'max:300'],
            'base_uri' => ['url'],
        ];

        if (($validator = Validator::make($config, $rules))->fails()) {
            throw new InvalidClientConfigurationException($validator);
        }
    }

    /**
     * @return string|null
     */
    protected function getCertificatePath()
    {
        return null;
    }

    /**
     * @return bool
     */
    protected function shouldSavePdf()
    {
        return $this->config['storage']['auto_save'] === true;
    }

    /**
     * @return string
     */
    protected function storageDisk()
    {
        return $this->config['storage']['disk'];
    }

    /**
     * @return string
     */
    protected function storagePath()
    {
        return $this->config['storage']['path'];
    }

    /**
     * @return string
     */
    protected function stringifyBoolean($value)
    {
        return $value
            ? 'true'
            : 'false';
    }

    /**
     * @return string
     */
    protected function commonCurrencyFormat($value)
    {
        return number_format($value, 3, '.', '');
    }

    protected function writeCdataElement(XMLWriter &$writer, $element, $content)
    {
        $writer->startElement($element);
        $writer->writeCdata($content);
        $writer->endElement();
    }

    /**
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function modelValidator(AbstractModel $abstractModel, array $rules)
    {
        return Validator::make($abstractModel->toApiArray(), $rules);
    }

    /**
     * Validates invoice against the specified rules
     *
     * @return bool
     *
     * @throws ReceiptValidationException
     * @throws InvoiceValidationException
     */
    protected function validateModel(AbstractModel $model, array $rules)
    {
        $validator = $this->modelValidator($model, $rules);

        if ($validator->fails()) {
            if ($model instanceof AbstractInvoice) {
                throw new InvoiceValidationException($model, $validator);
            } elseif ($model instanceof Receipt) {
                throw new ReceiptValidationException($model, $validator);
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function isAuthenticationError(ResponseInterface $response)
    {
        try {
            $xml = $this->parse((string) $response->getBody());
            if (isset($xml['sikeres']) && $xml['sikeres'] === 'false'
                && isset($xml['hibauzenet']) && $xml['hibauzenet'] === 'Sikertelen bejelentkezés.') {
                return true;
            }
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Converts API error to catchable local exception
     *
     *
     * @throws CommonResponseException
     */
    protected function convertResponseToException(ResponseInterface $response)
    {
        $code = 500;
        $message = 'Unknown error';

        if ($response->hasHeader('szlahu_error_code')) {
            $code = $response->getHeader('szlahu_error_code')[0];
        } elseif ($this->isAuthenticationError($response)) {
            $code = 2;
        } elseif (preg_match("/<hibakod>([0-9]+)\<\/hibakod>/", (string) $response->getBody(), $matches)) {
            if (isset($matches[1]) && is_numeric($matches[1])) {
                $code = (int) $matches[1];
            }
        }

        if ($response->hasHeader('szlahu_error')) {
            $message = $response->getHeader('szlahu_error')[0];
        }

        $httpStatusCode = $response->getStatusCode();

        $exceptionClass = null;

        switch ((int) $code) {
            case 3:
                $exceptionClass = AuthenticationException::class;
                break;
            case 54:
                $exceptionClass = CannotCreateInvoiceException::class;
                break;
            case 261:
            case 264:
                $exceptionClass = InvalidGrossPriceValueException::class;
                break;
            case 202:
                $exceptionClass = InvalidInvoicePrefixException::class;
                break;
            case 259:
            case 262:
                $exceptionClass = InvalidNetPriceValueException::class;
                break;
            case 338:
                $exceptionClass = ReceiptAlreadyExistsException::class;
                break;
            case 339:
                $exceptionClass = ReceiptNotFoundException::class;
                break;
            case 260:
            case 263:
                $exceptionClass = InvalidVatRateValueException::class;
                break;
            case 56:
                $exceptionClass = InvoiceNotificationSendingException::class;
                break;
            case 49:
                $exceptionClass = KeystoreOpeningException::class;
                break;
            case 53:
                $exceptionClass = NoXmlFileException::class;
                break;
            case 1:
                $exceptionClass = RemoteMaintenanceException::class;
                break;
            case 55:
                $exceptionClass = UnsuccessfulInvoiceSignatureException::class;
                break;
            case 57:
                $exceptionClass = XmlReadingException::class;
                break;
            default:
                throw new CommonResponseException($response, $message ?: 'Unknown error', $httpStatusCode ?: 500);
        }

        if ($exceptionClass) {
            throw new $exceptionClass($response);
        }
    }

    /**
     * Process the response obtained over HTTP
     *
     * @return ResponseInterface
     *
     * @throws CommonResponseException
     */
    protected function processResponse(ResponseInterface $response)
    {
        if ($response->hasHeader('szlahu_error_code') or
            str_contains((string) $response->getBody(), '<sikeres>false</sikeres>')) {
            $this->convertResponseToException($response);
        }

        return $response;
    }

    /**
     * Sends request to Szamlazz.hu server
     *
     * @param  string  $uri
     * @param  string  $method
     * @return ResponseInterface
     */
    protected function send(string $action, string $contents, $uri = '/szamla/', $method = 'POST')
    {
        $options = [
            'timeout' => $this->config['timeout'],
            'base_uri' => $this->config['base_uri'],
        ];

        /*
         * Inject content body into request
         * */
        if ($action && $contents) {
            $options['multipart'] = [
                [
                    'name' => $action,
                    'filename' => 'invoice.xml',
                    'contents' => $contents,
                ],
            ];
        }

        return $this->client->requestAsync($method, $uri, $options)
            ->then(function (Response $response) {
                return $this->processResponse($response);
            }, function () {
            })
            ->wait();
    }

    /**
     * @param  string  $pdfContent
     * @param  string  $as
     * @return bool
     */
    protected function updatePdfFile($disk, $path, $pdfContent, $as)
    {
        $fullPath = $path."/$as";

        return ($this->shouldSavePdf() && ! Storage::disk($disk)->exists($fullPath))
            ? Storage::disk($disk)->put($fullPath, $pdfContent)
            : false;
    }

    /**
     * Writes auth credentials via the given writer
     */
    protected function writeCredentials(XMLWriter &$writer)
    {
        if (isset($this->config['credentials']['api_key'])) {
            $writer->writeElement('szamlaagentkulcs', $this->config['credentials']['api_key']);
        } else {
            $writer->writeElement('felhasznalo', $this->config['credentials']['username']);
            $writer->writeElement('jelszo', $this->config['credentials']['password']);
        }
    }

    /**
     * @param  string  $invoiceClass
     * @return AbstractInvoice|ClientAccessor|Invoice|ProformaInvoice
     */
    protected function invoiceFactory($invoiceClass, array $head, array $customer, array $merchant, array $items)
    {
        /** @var ClientAccessor $invoice */
        $invoice = new $invoiceClass($head, $items, $customer, $merchant);
        $invoice->setClient($this);

        return $invoice;
    }

    /**
     * @param  callable|Closure  $write
     * @param  string  $namespace
     * @param  string  $schemaLocation
     * @return string
     */
    protected function writer(
        $write,
        $root,
        $namespace,
        $schemaLocation
    ) {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);

        // Write NS attributes
        $writer->startElementNs(null, $root, $namespace);
        $writer->writeAttributeNs('xsi', 'schemaLocation', null, $schemaLocation);
        $writer->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');

        $write($writer);
        $writer->endElement();

        return $writer->outputMemory();
    }

    /**
     * @return bool
     *
     * @throws ReceiptValidationException|ModelValidationException
     */
    public function validateReceiptForSaving(Receipt $receipt)
    {
        return $this->validateModel($receipt, $this->validationRulesForSavingReceipt());
    }

    /**
     * @return bool
     *
     * @throws InvoiceValidationException|ModelValidationException
     */
    public function validateInvoiceForSaving(Invoice $invoice)
    {
        return $this->validateModel($invoice, $this->validationRulesForSavingInvoice());
    }

    /**
     * @return bool
     *
     * @throws InvoiceValidationException|ModelValidationException
     */
    public function validateProformaInvoiceForSaving(ProformaInvoice $invoice)
    {
        return $this->validateModel($invoice, $this->validationRulesForSavingInvoice());
    }

    /**
     * @param  bool  $withoutPdf
     * @param  null  $emailSubject
     * @param  null  $emailMessage
     * @return InvoiceCreationResponse
     *
     * @throws ModelValidationException
     */
    public function uploadProFormaInvoice(ProformaInvoice $invoice, $withoutPdf = false, $emailSubject = null, $emailMessage = null)
    {
        return $this->uploadCommonInvoice($invoice, $withoutPdf, $emailSubject, $emailMessage);
    }

    /**
     * Creates invoice
     *
     * @param  bool  $withoutPdf
     * @param  null  $emailSubject
     * @param  null  $emailMessage
     * @return InvoiceCreationResponse
     *
     * @throws \zoparga\SzamlazzHu\Client\Errors\ModelValidationException
     */
    public function uploadInvoice(Invoice $invoice, $withoutPdf = false, $emailSubject = null, $emailMessage = null)
    {
        return $this->uploadCommonInvoice($invoice, $withoutPdf, $emailSubject, $emailMessage);
    }

    /**
     * @param  bool  $withoutPdf
     * @param  null  $emailSubject
     * @param  null  $emailMessage
     * @return InvoiceCreationResponse
     *
     * @throws ModelValidationException
     */
    protected function uploadCommonInvoice(AbstractInvoice $invoice, $withoutPdf = false, $emailSubject = null, $emailMessage = null)
    {
        /*
         * Use fallback merchant.
         * */
        if (! $invoice->hasMerchant() && $this->defaultMerchant === null) {
            throw new InvalidArgumentException('No merchant configured on invoice! Please specify the merchant on the invoice or setup the default merchant in the configuration!');
        } elseif (! $invoice->hasMerchant() && $this->defaultMerchant) {
            $invoice->setMerchant($this->defaultMerchant);
        }

        /*
         * Validate invoice for request
         * */
        $this->validateModel($invoice, $this->validationRulesForSavingInvoice());

        /*
         * Build invoice XML
         */
        $contents = $this->writer(
            function (XMLWriter $writer) use (&$invoice, &$withoutPdf, &$emailSubject, &$emailMessage) {
                /*
                 * Common settings of invoice
                 * */
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);
                $writer->writeElement('eszamla', $this->stringifyBoolean($invoice->isElectronic));
                //$writer->writeElement('kulcstartojelszo', '');
                $writer->writeElement('szamlaLetoltes', $this->stringifyBoolean(! $withoutPdf));
                $writer->writeElement('valaszVerzio', 2);
                $writer->writeElement('aggregator', '');

                $writer->endElement();

                /*
                 * Header info of invoice
                 * */
                $writer->startElement('fejlec');
                {
                    $writer->writeElement('keltDatum', $invoice->createdAt->format('Y-m-d'));
                    $writer->writeElement('teljesitesDatum', $invoice->fulfillmentAt->format('Y-m-d'));
                    $writer->writeElement('fizetesiHataridoDatum', $invoice->paymentDeadline->format('Y-m-d'));
                    $writer->writeElement('fizmod', $this->getPaymentMethodByAlias($invoice->paymentMethod));
                    $writer->writeElement('penznem', $invoice->currency);
                    $writer->writeElement('szamlaNyelve', $invoice->invoiceLanguage);
                    $this->writeCdataElement($writer, 'megjegyzes', $invoice->comment ?: '');
                    if ($invoice->exchangeRateBank) {
                        $writer->writeElement('arfolyamBank', $invoice->exchangeRateBank);
                    }
                    if ($invoice->exchangeRate) {
                        $writer->writeElement('arfolyam', number_format($invoice->exchangeRate, 3, '.', ''));
                    }
                    if ($invoice->orderNumber) {
                        $this->writeCdataElement($writer, 'rendelesSzam', $invoice->orderNumber);
                    }
                    $writer->writeElement('elolegszamla', $this->stringifyBoolean($invoice->isImprestInvoice));
                    $writer->writeElement('vegszamla', $this->stringifyBoolean($invoice->isFinalInvoice));
                    $writer->writeElement('helyesbitoszamla', $this->stringifyBoolean($invoice->isReplacementInvoice));
                    $writer->writeElement('dijbekero', $this->stringifyBoolean(($invoice instanceof ProformaInvoice)));
                    if ($invoice->invoicePrefix) {
                        $this->writeCdataElement($writer, 'szamlaszamElotag', $invoice->invoicePrefix);
                    }
                    $writer->writeElement('fizetve', $this->stringifyBoolean($invoice->isPaid));
                    $writer->writeElement('elonezetpdf', $this->stringifyBoolean($invoice->isPreview));
                }
                $writer->endElement();

                /*
                 * Merchant details
                 * */
                $writer->startElement('elado');

                $writer->writeElement('bank', $invoice->merchantBank);
                $writer->writeElement('bankszamlaszam', $invoice->merchantBankAccountNumber);
                if ($invoice->merchantReplyEmailAddress) {
                    $writer->writeElement('emailReplyto', $invoice->merchantReplyEmailAddress);
                }
                if ($emailSubject) {
                    $this->writeCdataElement($writer, 'emailTargy', $emailSubject);
                }
                if ($emailMessage) {
                    $this->writeCdataElement($writer, 'emailSzoveg', $emailMessage);
                }

                $writer->endElement();

                /*
                 * Customer details
                 * */
                $writer->startElement('vevo');
                {
                    $this->writeCdataElement($writer, 'nev', $invoice->customerName);
                    if ($invoice->customerCountry) {
                        $this->writeCdataElement($writer, 'orszag', $invoice->customerCountry);
                    }
                    $this->writeCdataElement($writer, 'irsz', $invoice->customerZipCode);
                    $this->writeCdataElement($writer, 'telepules', $invoice->customerCity);
                    $this->writeCdataElement($writer, 'cim', $invoice->customerAddress);
                    if ($invoice->customerEmail) {
                        $writer->writeElement('email', $invoice->customerEmail);
                    }
                    $writer->writeElement('sendEmail', $this->stringifyBoolean($invoice->customerReceivesEmail));
                    if ($invoice->customerTaxNumber) {
                        $this->writeCdataElement($writer, 'adoszam', $invoice->customerTaxNumber);
                    }
                    if ($invoice->customerEuTaxNumber) {
                        $writer->writeElement('adoszamEU', $invoice->customerEuTaxNumber);
                    }
                    if ($invoice->customerTaxSubject) {
                        $writer->writeElement('adoalany', $invoice->customerTaxSubject);
                    }
                    if ($invoice->customerShippingName) {
                        $this->writeCdataElement($writer, 'postazasiNev', $invoice->customerShippingName);
                    }
                    if ($invoice->customerShippingCountry) {
                        $this->writeCdataElement($writer, 'postazasiOrszag', $invoice->customerShippingCountry);
                    }
                    if ($invoice->customerShippingZipCode) {
                        $this->writeCdataElement($writer, 'postazasiIrsz', $invoice->customerShippingZipCode);
                    }
                    if ($invoice->customerShippingCity) {
                        $this->writeCdataElement($writer, 'postazasiTelepules', $invoice->customerShippingCity);
                    }
                    if ($invoice->customerShippingAddress) {
                        $this->writeCdataElement($writer, 'postazasiCim', $invoice->customerShippingAddress);
                    }
                }
                $writer->endElement();

                /*
                 * Apply items
                 * */
                $writer->startElement('tetelek');
                $invoice->items()->each(function (array $item) use (&$writer) {
                    $writer->startElement('tetel');

                    $this->writeCdataElement($writer, 'megnevezes', $item['name']);
                    $writer->writeElement('mennyiseg', $item['quantity']);
                    $this->writeCdataElement($writer, 'mennyisegiEgyseg', $item['quantityUnit']);
                    $writer->writeElement('nettoEgysegar', $this->commonCurrencyFormat($item['netUnitPrice']));
                    $writer->writeElement('afakulcs', $item['taxRate']);

                    $netUnitPrice = $item['netUnitPrice'];
                    $taxRate = is_numeric($item['taxRate']) ? $item['taxRate'] : 0;
                    $quantity = $item['quantity'];
                    $netPrice = isset($item['netPrice'])
                        ? $item['netPrice']
                        : ($netUnitPrice * $quantity);
                    $grossPrice = isset($item['grossPrice'])
                        ? $item['grossPrice']
                        : round($netPrice * (1 + ($taxRate / 100)), 2);
                    $taxValue = isset($item['taxValue'])
                        ? $item['taxValue']
                        : ($grossPrice - $netPrice);

                    $writer->writeElement('nettoErtek', $this->commonCurrencyFormat($netPrice));
                    $writer->writeElement('afaErtek', $this->commonCurrencyFormat($taxValue));
                    $writer->writeElement('bruttoErtek', $this->commonCurrencyFormat($grossPrice));
                    if (isset($item['comment']) && ! empty($item['comment'])) {
                        $this->writeCdataElement($writer, 'megjegyzes', $item['comment']);
                    }

                    $writer->endElement();
                });
                $writer->endElement();
            },
            ...self::ACTIONS['UPLOAD_COMMON_INVOICE']['schema']
        );

        $responseClass = $invoice->isPreview
            ? InvoicePreviewResponse::class
            : InvoiceCreationResponse::class;

        /*
         * Send invoice
         * */
        $response = new $responseClass(
            $this,
            $this->send(self::ACTIONS['UPLOAD_COMMON_INVOICE']['name'], $contents)
        );

        // Assign invoice number on invoice
        $invoice->invoiceNumber = $response->invoiceNumber;

        /*
         * Saving (proforma) invoice PDF files - generated by the API
         * */
         if ($response->pdfBase64) {
            $invoice->pdf = $response->pdfBase64;
            if (!$withoutPdf &&
                $this->shouldSavePdf()
            ) {
                $disk = $this->storageDisk();
                $path = $this->storagePath();

                /*
                 * Save generated invoice PDF file
                 * */
                if ($response->pdfBase64 !== null) {
                    $this->updatePdfFile(
                        $disk,
                        $path,
                        base64_decode($response->pdfBase64),
                        "$response->invoiceNumber.pdf"
                    );
                }
            }
        }

        return $response;
    }

    /**
     * Deletes only proforma invoices
     *
     * @return ProformaInvoiceDeletionResponse
     *
     * @throws ModelValidationException
     */
    public function deleteProFormaInvoice(ProformaInvoice $invoice)
    {
        $this->validateModel($invoice, $this->validationRulesForDeletingProformaInvoice());

        $contents = $this->writer(
            function (XMLWriter $writer) use ($invoice) {
                /*
                 * Common settings of invoice
                 * */
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);

                $writer->endElement();

                $writer->startElement('fejlec');

                $writer->writeElement('szamlaszam', $invoice->invoiceNumber);

                $writer->endElement();
            },
            ...self::ACTIONS['DELETE_PROFORMA_INVOICE']['schema']
        );

        return new ProformaInvoiceDeletionResponse(
            $invoice,
            $this,
            $this->send(self::ACTIONS['DELETE_PROFORMA_INVOICE']['name'], $contents)
        );
    }

    /**
     * Cancels (existing) invoice
     *
     * @param  bool  $withoutPdf
     * @param  null  $emailSubject
     * @param  null  $emailMessage
     * @return InvoiceCancellationResponse
     *
     * @throws InvoiceValidationException
     * @throws ReceiptValidationException
     */
    public function cancelInvoice(Invoice $invoice, $withoutPdf = false, $emailSubject = null, $emailMessage = null)
    {
        /*
         * Validate invoice for request
         * */
        $this->validateModel($invoice, $this->validationRulesForCancellingInvoice());

        /*
         * Build invoice XML
         */
        $contents = $this->writer(
            function (XMLWriter $writer) use (&$invoice, &$emailSubject, &$emailMessage) {
                /*
                 * Common settings of invoice
                 * */
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);
                $writer->writeElement('eszamla', $this->stringifyBoolean($invoice->isElectronic));
                //$writer->writeElement('kulcstartojelszo', '');
                $writer->writeElement('szamlaLetoltes', $this->stringifyBoolean(false));
                $writer->writeElement('szamlaLetoltesPld', 1);

                $writer->endElement();

                $writer->startElement('fejlec');

                $writer->writeElement('szamlaszam', $invoice->invoiceNumber);
                $writer->writeElement('keltDatum', $invoice->createdAt->format('Y-m-d'));
                $writer->writeElement('teljesitesDatum', $invoice->fulfillmentAt->format('Y-m-d'));
                $writer->writeElement('tipus', 'SS');

                $writer->endElement();

                $writer->startElement('elado');

                if ($invoice->customerReceivesEmail) {
                    if ($invoice->merchantReplyEmailAddress) {
                        $writer->writeElement('emailReplyto', $invoice->merchantReplyEmailAddress);
                    }
                    if ($emailSubject) {
                        $this->writeCdataElement($writer, 'emailTargy', $emailSubject);
                    }
                    if ($emailMessage) {
                        $this->writeCdataElement($writer, 'emailSzoveg', $emailMessage);
                    }
                }

                $writer->endElement();

                if ($invoice->customerReceivesEmail) {
                    $writer->startElement('vevo');
                    {
                        $writer->writeElement('email', $invoice->customerEmail);
                        // For some reason this does not work:
                        // $writer->writeElement('sendEmail', $this->stringifyBoolean($invoice->customerReceivesEmail));
                    }
                }
                $writer->endElement();
            },
            ...self::ACTIONS['CANCEL_INVOICE']['schema']
        );

        $response = new InvoiceCancellationResponse(
            $invoice,
            $this,
            $this->send(self::ACTIONS['CANCEL_INVOICE']['name'], $contents)
        );

        // Since the API responds with XML or PDF we have to choose one.
        if (! $withoutPdf && $this->shouldSavePdf()) {
            $contents = $this->writer(
                function (XMLWriter $writer) use (&$invoice) {
                    /*
                     * Common settings of invoice
                     * */
                    $writer->startElement('beallitasok');

                    $this->writeCredentials($writer);
                    $writer->writeElement('eszamla', $this->stringifyBoolean($invoice->isElectronic));
                    // $writer->writeElement('kulcstartojelszo', '');
                    $writer->writeElement('szamlaLetoltes', $this->stringifyBoolean(true));
                    $writer->writeElement('szamlaLetoltesPld', 1);

                    $writer->endElement();

                    $writer->startElement('fejlec');

                    $writer->writeElement('szamlaszam', $invoice->invoiceNumber);

                    $writer->endElement();
                },
                ...self::ACTIONS['CANCEL_INVOICE']['schema']
            );

            $pdf = (string) $this->send(self::ACTIONS['CANCEL_INVOICE']['name'], $contents)->getBody();
            $this->updatePdfFile($this->storageDisk(), $this->storagePath(), $pdf, "$response->cancellationInvoiceNumber.pdf");
        }

        return $response;
    }

    /**
     * @return Invoice|ProformaInvoice|AbstractInvoice
     *
     * @throws CommonResponseException
     */
    public function getInvoiceByOrderNumber($orderNumber)
    {
        try {
            return $this->getInvoiceByOrderNumberOrFail($orderNumber);
        } catch (InvoiceNotFoundException $exception) {
            return null;
        }
    }

    /**
     * @return mixed
     *
     * @throws CommonResponseException
     * @throws InvoiceNotFoundException
     */
    public function getInvoiceByOrderNumberOrFail($orderNumber)
    {
        [$head, $customer, $merchant, $items] = $this->getCommonInvoice(null, $orderNumber);

        return $this->invoiceFactory(
            $head['isPrepaymentRequest'] ? ProformaInvoice::class : Invoice::class,
            $head,
            $customer,
            $merchant,
            $items
        );
    }

    /**
     * @param  string|Invoice  $invoice
     * @return AbstractInvoice|Invoice|ProformaInvoice
     *
     * @throws CommonResponseException
     * @throws InvoiceNotFoundException
     */
    public function getInvoiceOrFail($invoice)
    {
        if (! is_string($invoice) && ! $invoice instanceof Invoice) {
            throw new InvalidArgumentException('Invoice needs to be either invoice number string or instance of ['.Invoice::class.']');
        }

        return $this->invoiceFactory(Invoice::class, ...$this->getCommonInvoice($invoice instanceof Invoice ? $invoice->invoiceNumber : $invoice));
    }

    /**
     * @param  string|Invoice  $invoice
     * @return null|AbstractInvoice|Invoice|ProformaInvoice
     *
     * @throws CommonResponseException
     */
    public function getInvoice($invoice)
    {
        try {
            return $this->getInvoiceOrFail($invoice);
        } catch (InvoiceNotFoundException $exception) {
            return null;
        }
    }

    /**
     * @param  string|ProformaInvoice  $invoice
     * @return AbstractInvoice|Invoice|ProformaInvoice
     *
     * @throws CommonResponseException
     * @throws InvoiceNotFoundException
     * @throws InvalidArgumentException
     */
    public function getProformaInvoiceOrFail($invoice)
    {
        if (! is_string($invoice) && ! $invoice instanceof ProformaInvoice) {
            throw new InvalidArgumentException('Invoice needs to be either invoice number string or instance of ['.ProformaInvoice::class.']');
        }

        return $this->invoiceFactory(ProformaInvoice::class, ...$this->getCommonInvoice($invoice instanceof ProformaInvoice ? $invoice->invoiceNumber : $invoice));
    }

    /**
     * @param  string|ProformaInvoice  $invoice
     * @return null|ProformaInvoice
     *
     * @throws CommonResponseException
     */
    public function getProformaInvoice($invoice)
    {
        try {
            return $this->getProformaInvoiceOrFail($invoice);
        } catch (InvoiceNotFoundException $exception) {
            return null;
        }
    }

    /**
     * @param  string|AbstractInvoice|null  $invoiceNumber
     * @param  null  $orderNumber
     * @return array
     *
     * @throws CommonResponseException
     * @throws InvoiceNotFoundException
     * @throws InvalidArgumentException
     */
    protected function getCommonInvoice($invoiceNumber = null, $orderNumber = null)
    {
        if (! $invoiceNumber && ! $orderNumber) {
            throw new InvalidArgumentException('Invoice or the orderNumber must be specified!');
        }

        /*
         * Build invoice XML
         */
        $contents = $this->writer(
            function (XMLWriter $writer) use (&$invoiceNumber, &$orderNumber) {
                $this->writeCredentials($writer);
                if ($orderNumber) {
                    $writer->writeElement('rendelesSzam', $orderNumber);
                } else {
                    $writer->writeElement('szamlaszam', $invoiceNumber);
                }
                $writer->writeElement('pdf', $this->stringifyBoolean(true));
            },
            ...self::ACTIONS['GET_COMMON_INVOICE']['schema']
        );

        try {
            /*
             * Response obtained
             * */
            $contents = (string) $this->send(self::ACTIONS['GET_COMMON_INVOICE']['name'], $contents)->getBody();

            $xml = $this->parse($contents);

            // CHECK PAYED STATUS & CALCULATE IF IT IS FULLY PAID

            if (isset($xml['kifizetesek']) && isset($xml['kifizetesek']['kifizetes'])) {
                if (array_column($xml['kifizetesek']['kifizetes'], 'osszeg')) {
                    $totalPaid = array_sum(array_column($xml['kifizetesek']['kifizetes'], 'osszeg'));
                } else {
                    $totalPaid = $xml['kifizetesek']['kifizetes']['osszeg'];
                }
                if (array_column($xml['kifizetesek']['kifizetes'], 'datum')) {
                    $paidAt = max(array_column($xml['kifizetesek']['kifizetes'], 'datum'));
                } else {
                    $paidAt = $xml['kifizetesek']['kifizetes']['datum'];
                }
            } else {
                $totalPaid = 0;
                $paidAt = null;
            }
            $totalPaid = (int) $totalPaid;
            $totalSum = (int) $xml['osszegek']['totalossz']['brutto'];

            // General attributes
            $head = [
                'isElectronic' => Str::startsWith($xml['alap']['szamlaszam'], 'E-'),
                'isPrepaymentRequest' => Str::startsWith($xml['alap']['szamlaszam'], 'D-'),
                'invoiceNumber'       => $xml['alap']['szamlaszam'],
                'createdAt'           => Carbon::createFromFormat('Y-m-d', $xml['alap']['kelt']),
                'fulfillmentAt'       => Carbon::createFromFormat('Y-m-d', $xml['alap']['telj']),
                'paymentDeadline'     => Carbon::createFromFormat('Y-m-d', $xml['alap']['fizh']),
                'paymentMethod'       => $this->getPaymentMethod(html_entity_decode($xml['alap']['fizmod'])),
                'invoiceLanguage'     => $xml['alap']['nyelv'],
                'currency'            => $xml['alap']['devizanem'],
                'exchangeRateBank'    => isset($xml['alap']['devizabank']) ? $xml['alap']['devizabank'] : null,
                'exchangeRate'        => isset($xml['alap']['devizaarf']) ? $xml['alap']['devizaarf'] : null,
                'comment'             => html_entity_decode($xml['alap']['megjegyzes']),
                'isKata'              => $xml['alap']['kata'] == 'true',
                'totalSum'            => $totalSum,
                'totalPaid'           => $totalPaid,
                'isPaid'              => $totalSum == $totalPaid ? true : false,
                'paidAt'              => $paidAt ? Carbon::createFromFormat('Y-m-d', $paidAt) : null,
                'pdf'                 => $xml['pdf'] ?? null ,
            ];

            if (isset($xml['alap']['hivdijbekszam'])) {
                $head['proFormaInvoiceNumber'] = $xml['alap']['hivdijbekszam'];
            }

            if (isset($xml['alap']['rendelesszam'])) {
                $head['orderNumber'] = $xml['alap']['rendelesszam'];
            }

            // Customer fields
            $customer = [
                'customerName'      => html_entity_decode($xml['vevo']['nev']),
                'customerEmail'     => $xml['vevo']['email'] ?? null,
                'customerCountry'   => html_entity_decode($xml['vevo']['cim']['orszag'] ?? ''),
                'customerZipCode'   => $xml['vevo']['cim']['irsz'],
                'customerCity'      => html_entity_decode($xml['vevo']['cim']['telepules']),
                'customerAddress'   => $xml['vevo']['cim']['cim'],
                'customerTaxNumber' => $xml['vevo']['adoszam'],
                'customerEuTaxNumber' => $xml['vevo']['adoszameu'] ?? null,
            ];

            // Merchant fields
            $merchant = [
                'merchantName' => html_entity_decode($xml['szallito']['nev']),
                'merchantCountry' => html_entity_decode($xml['szallito']['cim']['orszag']),
                'merchantZipCode' => html_entity_decode($xml['szallito']['cim']['irsz']),
                'merchantCity' => html_entity_decode($xml['szallito']['cim']['telepules']),
                'merchantAddress' => html_entity_decode($xml['szallito']['cim']['cim']),
                'merchantTaxNumber' => html_entity_decode($xml['szallito']['adoszam']),
                'merchantEuTaxNumber' => isset($xml['szallito']['adoszameu']) ? html_entity_decode($xml['szallito']['adoszameu']) : null,
                'merchantBank' => html_entity_decode($xml['szallito']['bank']['nev']),
                'merchantBankAccountNumber' => $xml['szallito']['bank']['bankszamla'],
            ];

            // Items
            $items = Collection::wrap($items = $this->normalizeToNumericArray($xml['tetelek']['tetel']))
                ->map(function ($item) {
                    return [
                        'name' => html_entity_decode($item['nev']),
                        'quantity' => (float) $item['mennyiseg'],
                        'quantityUnit' => $item['mennyisegiegyseg'],
                        'netUnitPrice' => (float) $item['nettoegysegar'],
                        'taxRate' => is_numeric($item['afakulcs']) ? (float) $item['afakulcs'] : $item['afakulcs'],
                        'totalNetPrice' => (float) $item['netto'],
                        'taxValue' => (float) $item['afa'],
                        'totalGrossPrice' => (float) $item['brutto'],
                        'comment' => html_entity_decode($item['megjegyzes']),
                    ];
                })
                ->toArray();
        } catch (CommonResponseException $exception) {
            if (Str::contains((string) $exception->getResponse()->getBody(), '(ismeretlen számlaszám).')) {
                throw new InvoiceNotFoundException($invoiceNumber);
            }

            throw $exception;
        } catch (ParserException $exception) {
            throw new InvoiceNotFoundException($invoiceNumber);
        }

        return [
            $head,
            $customer,
            $merchant,
            $items,
        ];
    }

    /**
     * @param  bool  $withoutPdf
     * @return ReceiptCreationResponse
     *
     * @throws ModelValidationException
     */
    public function uploadReceipt(Receipt $receipt, $withoutPdf = false)
    {
        /*
         * Validate receipt for request
         * */
        $this->validateModel($receipt, $this->validationRulesForSavingReceipt());

        $contents = $this->writer(
            function (XMLWriter $writer) use (&$receipt, &$withoutPdf) {
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);
                $writer->writeElement('pdfLetoltes', $this->stringifyBoolean(! $withoutPdf || $this->shouldSavePdf()));

                $writer->endElement();

                /*
                 * Header info of receipt
                 * */
                $writer->startElement('fejlec');

                $writer->writeElement('hivasAzonosito', $receipt->orderNumber);
                $writer->writeElement('elotag', $receipt->prefix);
                $writer->writeElement('fizmod', $this->getPaymentMethodByAlias($receipt->paymentMethod));
                $writer->writeElement('penznem', $receipt->currency);
                if ($receipt->exchangeRateBank) {
                    $writer->writeElement('devizabank', $receipt->exchangeRateBank);
                }
                if ($receipt->exchangeRate) {
                    $writer->writeElement('devizaarf', $receipt->exchangeRate);
                }
                $writer->writeElement('megjegyzes', $receipt->comment);

                $writer->endElement();

                /*
                 * Writing items
                 * */
                $writer->startElement('tetelek');
                $receipt->items()->each(function (array $item) use (&$writer) {
                    $writer->startElement('tetel');

                    $this->writeCdataElement($writer, 'megnevezes', $item['name']);
                    $writer->writeElement('mennyiseg', $item['quantity']);
                    $this->writeCdataElement($writer, 'mennyisegiEgyseg', $item['quantityUnit']);
                    $writer->writeElement('nettoEgysegar', $this->commonCurrencyFormat($item['netUnitPrice']));
                    $writer->writeElement('afakulcs', $item['taxRate']);

                    $netUnitPrice = $item['netUnitPrice'];
                    $taxRate = is_numeric($item['taxRate']) ? $item['taxRate'] : 0;
                    $quantity = $item['quantity'];
                    $netPrice = isset($item['netPrice']) ? $item['netPrice'] : ($netUnitPrice * $quantity);
                    $grossPrice = isset($item['grossPrice']) ? $item['grossPrice'] : round($netPrice * (1 + ($taxRate / 100)), 2);
                    $taxValue = isset($item['taxValue']) ? $item['taxValue'] : ($grossPrice - $netPrice);

                    $writer->writeElement('netto', $this->commonCurrencyFormat($netPrice));
                    $writer->writeElement('afa', $this->commonCurrencyFormat($taxValue));
                    $writer->writeElement('brutto', $this->commonCurrencyFormat($grossPrice));

                    $writer->endElement();
                });
                $writer->endElement();

                /*
                 * Writing payments if present
                 * */
                if ($receipt->payments()->isNotEmpty()) {
                    $writer->startElement('kifizetesek');
                    $receipt->payments()->each(function ($payment) use (&$writer) {
                        $writer->startElement('kifizetes');

                        $writer->writeElement('fizetoeszkoz', $this->getPaymentMethodByAlias($payment['paymentMethod']));
                        $writer->writeElement('osszeg', $this->commonCurrencyFormat($payment['amount']));
                        if (isset($payment['comment']) && ! empty($payment['comment'])) {
                            $this->writeCdataElement($writer, 'leiras', $payment['comment']);
                        }

                        $writer->endElement();
                    });
                    $writer->endElement();
                }
            },
            ...self::ACTIONS['UPLOAD_RECEIPT']['schema']
        );

        $response = new ReceiptCreationResponse(
            $this,
            $this->send(self::ACTIONS['UPLOAD_RECEIPT']['name'], $contents)
        );

        // Fill up related attributes
        $receipt->fill([
            'callId' => $response->callId,
            'receiptNumber' => $response->receiptNumber,
            'createdAt' => $response->createdAt,
            'isCancelled' => $response->isCancelled,
        ]);

        /*
         * Saving receipt PDF files - generated by remote API
         * */
        if ($this->shouldSavePdf() && ! $withoutPdf) {
            $this->updatePdfFile(
                $this->storageDisk(),
                $this->storagePath(),
                base64_decode($response->pdfBase64),
                "$response->receiptNumber.pdf"
            );
        }

        return $response;
    }

    /**
     * @param  bool  $withoutPdf
     * @return ReceiptCancellationResponse
     *
     * @throws ModelValidationException
     */
    public function cancelReceipt(Receipt $receipt, $withoutPdf = false)
    {
        $this->validateModel($receipt, $this->validationRulesForCancellingReceipt());

        $contents = $this->writer(
            function (XMLWriter $writer) use (&$receipt, &$withoutPdf) {
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);
                $writer->writeElement('pdfLetoltes', $this->stringifyBoolean(! $withoutPdf || $this->shouldSavePdf()));

                $writer->endElement();

                $writer->startElement('fejlec');

                $writer->writeElement('nyugtaszam', $receipt->receiptNumber);

                $writer->endElement();
            },
            ...self::ACTIONS['CANCEL_RECEIPT']['schema']
        );

        $response = new ReceiptCancellationResponse(
            $receipt,
            $this,
            $this->send(self::ACTIONS['CANCEL_RECEIPT']['name'], $contents)
        );

        if ($response->pdfBase64 && $this->shouldSavePdf() && ! $withoutPdf) {
            $this->updatePdfFile(
                $this->storageDisk(),
                $this->storagePath(),
                base64_decode($response->pdfBase64),
                $response->originalReceiptNumber.'.pdf'
            );
        }

        // Modify related attributes
        $receipt->fill([
            'isCancelled' => true,
        ]);

        return $response;
    }

    /**
     * @param  bool  $withoutPdf
     * @return null|Receipt
     *
     * @throws ModelValidationException
     */
    public function getReceipt(Receipt $receipt, $withoutPdf = false)
    {
        try {
            return $this->getReceiptOrFail($receipt, $withoutPdf);
        } catch (ReceiptNotFoundException $exception) {
            return null;
        }
    }

    /**
     * @param  bool  $withoutPdf
     * @return Receipt
     *
     * @throws ModelValidationException
     * @throws ReceiptNotFoundException
     */
    public function getReceiptOrFail(Receipt $receipt, $withoutPdf = false)
    {
        $this->validateModel($receipt, $this->validationRulesForObtainingReceipt());

        return $this->getReceiptByReceiptNumberOrFail($receipt->receiptNumber, $withoutPdf);
    }

    /**
     * @param  bool  $withoutPdf
     * @return Receipt|null
     */
    public function getReceiptByReceiptNumber($receiptNumber, $withoutPdf = false)
    {
        try {
            return $this->getReceiptByReceiptNumberOrFail($receiptNumber, $withoutPdf);
        } catch (ReceiptNotFoundException $exception) {
            return null;
        }
    }

    /**
     * @param  string  $receiptNumber
     * @param  bool  $withoutPdf
     * @return Receipt
     *
     * @throws ReceiptNotFoundException
     */
    public function getReceiptByReceiptNumberOrFail($receiptNumber, $withoutPdf = false)
    {
        $contents = $this->writer(
            function (XMLWriter $writer) use (&$receiptNumber, &$withoutPdf) {
                $writer->startElement('beallitasok');

                $this->writeCredentials($writer);
                $writer->writeElement('pdfLetoltes', $this->stringifyBoolean(! $withoutPdf));

                $writer->endElement();

                $writer->startElement('fejlec');

                $writer->writeElement('nyugtaszam', $receiptNumber);

                $writer->endElement();
            },
            ...self::ACTIONS['GET_RECEIPT']['schema']
        );

        $contents = (string) $this->send(self::ACTIONS['GET_RECEIPT']['name'], $contents)->getBody();

        try {
            $xml = $this->parse($contents);

            // General attributes
            $head = [
                'callId' => isset($xml['nyugta']['alap']['hivasAzonosito']) ? $xml['nyugta']['alap']['hivasAzonosito'] : null,
                'receiptNumber' => $xml['nyugta']['alap']['nyugtaszam'],
                'isCancelled' => $xml['nyugta']['alap']['stornozott'] === 'true',
                'createdAt' => Carbon::createFromFormat('Y-m-d', $xml['nyugta']['alap']['kelt']),
                'exchangeRateBank' => isset($xml['nyugta']['alap']['devizabank'])
                    ? $xml['nyugta']['alap']['devizabank']
                    : null,
                'exchangeRate' => isset($xml['nyugta']['alap']['devizaarf'])
                    ? (float) $xml['nyugta']['alap']['devizaarf']
                    : null,
                'paymentMethod' => $this->getPaymentMethodByType(html_entity_decode($xml['nyugta']['alap']['fizmod'])),
                'currency' => $xml['nyugta']['alap']['penznem'],
                'comment' => isset($xml['nyugta']['alap']['megjegyzes']) ? $xml['nyugta']['alap']['megjegyzes'] : null,
                'originalReceiptNumber' => isset($xml['nyugta']['alap']['stornozottNyugtaszam'])
                    ? $xml['nyugta']['alap']['stornozottNyugtaszam']
                    : null,
            ];

            // Items
            $items = [];
            if (isset($xml['nyugta']['tetelek']) && isset($xml['nyugta']['tetelek']['tetel'])) {
                $items = Collection::wrap($this->normalizeToNumericArray($xml['nyugta']['tetelek']['tetel']))
                    ->map(function ($item) {
                        return [
                            'name' => $item['megnevezes'],
                            'quantity' => (float) $item['mennyiseg'],
                            'quantityUnit' => $item['mennyisegiEgyseg'],
                            'netUnitPrice' => (float) $item['nettoEgysegar'],
                            'totalNetPrice' => (float) $item['netto'],
                            'taxRate' => is_numeric($item['afakulcs']) ? (float) $item['afakulcs'] : $item['afakulcs'],
                            'taxValue' => (float) $item['afa'],
                            'totalGrossPrice' => (float) $item['brutto'],
                        ];
                    })
                    ->toArray();
            }

            // Payments
            $payments = [];
            if (isset($xml['nyugta']['kifizetesek']) && isset($xml['nyugta']['kifizetesek']['kifizetes'])) {
                $payments = Collection::wrap($this->normalizeToNumericArray($xml['nyugta']['kifizetesek']['kifizetes']))
                    ->map(function ($payment) {
                        return [
                            'paymentMethod' => $this->getPaymentMethodByType(html_entity_decode($payment['fizetoeszkoz'])),
                            'amount' => (float) $payment['osszeg'],
                            'comment' => isset($payment['leiras']) ? $payment['leiras'] : null,
                        ];
                    })
                    ->toArray();
            }

            /*
             * Saving receipt PDF files - generated by remote API
             * */
            if (isset($xml['nyugtaPdf']) && $xml['nyugtaPdf'] !== '' && $this->shouldSavePdf() && ! $withoutPdf) {
                $this->updatePdfFile(
                    $this->storageDisk(),
                    $this->storagePath(),
                    base64_decode($xml['nyugtaPdf']),
                    $xml['nyugta']['alap']['nyugtaszam'].'.pdf'
                );
            }
        } catch (ParserException $exception) {
            throw new ReceiptNotFoundException($receiptNumber);
        }

        return new Receipt($head, $items, $payments);
    }

    /**
     * @param string $taxNumber
     * @return QueryTaxPayerResponse
     */
    public function queryTaxPayer(string $taxNumber)
    {
        $contents = $this->writer(
            function (XMLWriter $writer) use ($taxNumber) {
                $this->writeCredentials($writer);
                $writer->writeElement('torzsszam', substr($taxNumber, 0, 8));
            },
            ...self::ACTIONS['QUERY_TAX_PAYER']['schema']
        );
        return new QueryTaxPayerResponse($this, $this->send(self::ACTIONS['QUERY_TAX_PAYER']['name'], $contents));
    }
}
