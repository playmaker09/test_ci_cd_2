<?php
namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\Event;
use Cake\Network\Exception\NotFoundException;
use Cake\Core\Configure;
use Cake\DataSource\ConnectionManager;
use Cake\I18n\Time;
use Cake\ORM\TableRegistry;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\Auth\DefaultPasswordHasher;
use Cake\Database\Expression\QueryExpression;

class MultiPrincipalExtractionOfReportsController extends AppController 
{

    public function index()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $uom= $this->setSource("ProductUoms",$company_id);
        $code= $this->setSource("Products",$company_id);
        $multiPrincipalUploadedCodesTbl= $this->setSource("multi_principal_uploaded_codes",$company_id);
        $productCode = $code->getCompanyProductCode($company_id);
        $product = $uom->getCompanyProductUoms($company_id);
        
        if( $company_id == "200986" ) {
            $companies_array = ['200987', '200988'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000342" || $company_id == "000357" ) {
            $companies_array = ['000755'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000628" ) {
            $companies_array = ['001006' ];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000255" ) {
            $companies_array = ['000755', '100219'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else {
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipals( $company_id );
        };
        
        $this->set('principals', $principals);
        $this->set('product', $product);
        $this->set('productCode', $productCode);
        $this->set(compact('user', 'company_id'));
    }

    public function countBaof(){
		$this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';
        $result = [];
        $result['message'] = [];
        $result['total'] = 0;

        $salesOrdersTbl = $this->setSource('sales_orders', $company_id);
        $multiPrincipalUserSettingTbl = $this->setSource('multi_principal_user_settings', $company_id);

        $usernames = $multiPrincipalUserSettingTbl->getAllUsername($company_id);
        $usernames = $multiPrincipalUserSettingTbl->getValidatedUser( $company_id, $principal, $usernames );
        $branch_codes = $this->getValidatedBranches($company_id, $principal);
        $account_codes = $this->getValidatedAccounts($company_id, $principal);
        $product_codes = $this->getMappedAndAssignedProducts( $company_id, $principal );

        $count_username = count( $usernames );
        $count_branch_code = count( $branch_codes );
        $count_account_codes = count( $account_codes );
        $count_product_codes = count( $product_codes );

        if( $count_username == 0 ) {
            $result['message'][] = "No Username Found in Mapping";
        }

        if( $count_branch_code == 0 ) {
            $result['message'][] = "No Branches Found in Mapping";
        }

        if( $count_account_codes == 0 ) {
            $result['message'][] = "No Accounts Found in Mapping";
        }

        if( $count_product_codes == 0 ) {
            $result['message'][] = "No Products Found in Mapping";
        }
        
        if( empty($result['message']) ) {
    
            $where = array(
                'sales_orders.company_id' => $company_id,
                "sales_orders.timestamp BETWEEN '" . $startDate . " 00:00:00' AND '" . $endDate . " 23:59:59'",
                'sales_orders.deleted' => 0,
                'sales_orders.username IN' => $usernames,
                'sales_orders.branch_code IN' => $branch_codes,
                'sales_orders.account_code IN' => $account_codes,
                'sales_order_items.product_code IN' => $product_codes,
            );
    
            $group = [
                'sales_orders.sales_order_number'
            ];

            $salesOrders = [
                'table' => 'sales_order_items',
                'alias' => 'sales_order_items',
                'type' => 'INNER',
                'conditions' => array(
                    'sales_orders.sales_order_number = sales_order_items.sales_order_number'
                    )
            ];
            
            $multiPrincipalProductSettings = [
                'table' => 'multi_principal_principal_product_settings',
                'alias' => 'MultiPrincipalPrincipalProductSetting',
                'type' => 'INNER',
                'conditions' => array(
                    'MultiPrincipalPrincipalProductSetting.item_code = sales_order_items.product_code',
                    'MultiPrincipalPrincipalProductSetting.status' => 1,
                    'MultiPrincipalPrincipalProductSetting.company_id' => $company_id
                )
            ];
    
    
            $records = $salesOrdersTbl->find()
                        ->group($group)
                        ->where($where)
                        ->join($salesOrders)
                        ->join($multiPrincipalProductSettings)
                        ->count();

            $result['total'] = $records;            
        }
    
        echo json_encode( $result );
        exit();

	}

    public function pushDataBaofToTemp()
    {
		$this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $records = $this->getAllBaof($start_date, $end_date, $company_id, $index, $selected_principal);
        $saveToTemp = $this->saveBaofToTemp($records, $company_id, $selected_principal);
        
        echo json_encode($saveToTemp);	
		exit();
    }

    public function saveBaofToTemp( $records, $source_company_id, $principal_company_id )
    {
        //$source_company_id = $users company id
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempSalesOrdersTable = $this->setSource('temp_sales_orders', $principal_company_id);

        $error = array();
		$errorMessage = array();
		$errorCount = 0;

		$errorAccount    = array();
		$errorBranch = array();
		$errorWarehouse = array();
		$errorProduct = array();

        $result = [];
        $result['message'] = [];
        $result['errorCount'] = 0;
        
		foreach ($records as $key => $data) {

			$form = $records[0];
			$formId = $data['id'];
			$system_id = $form['system_id'];
			$accountCode = $form['account_code'];
			$branchCode = $form['branch_code'];
			
			$salesOrderItems = $data['SalesOrderItem'];
			$BookingApprovalStatus = $data['BookingApprovalStatus']; 
			$BookingApprovalEncharge = $data['BookingApprovalEncharge']; 
			$ActualInvoice = $data['ActualInvoice'];
			$ActualInvoiceItem = $data['ActualInvoiceItem'];
			$date = date('Y-m-d H:i:s');
			
			$tempSalesOrderCond = array(
				'original_id' => $formId,
				'from_company_id' => $source_company_id,
				'company_id' => $principal_company_id
			);
            
			$doExist = $tempSalesOrdersTable->find()->where($tempSalesOrderCond)->first();


			//unset the fields so that it can be easily transfer
			$form['company_id'] = $principal_company_id;
			if(!$doExist){
                
                $counterpartAccountCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $accountCode, 1);
                $counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $branchCode, 2);
                $counterpartUsername = $this->getCounterpartCodes($source_company_id, $principal_company_id, $data['username'], 10);
                

                // skip this iteration
                if($counterpartAccountCode == "" || $counterpartBranchCode == ""){ 
                    
                    if($counterpartAccountCode == ""){ array_push($errorAccount, $accountCode);}
                    
                    if($counterpartBranchCode == ""){ array_push($errorBranch, $branchCode); }

                    $connections->rollback();
                    $errorCount++;
                    continue; 
                } 

                if( $counterpartUsername == "" ) {
                    $errorCount++;
                    continue; 
                }

				$form['account_id'] = $this->getAccountId($counterpartAccountCode['code'], $principal_company_id); 
				$form['branch_id'] = $this->getBranchId($counterpartBranchCode['code'], $principal_company_id);
                
				if($form['account_id'] == ""){
                    $errorMessage[] = "The Mapped Account code is not found : <strong>{$counterpartAccountCode}</strong> <br>";
					$errorCount++;
				}
				if($form['branch_id'] == ""){
                    $errorMessage[] = "The Mapped Branch code is not found : <strong>{$counterpartBranchCode}</strong> <br>";
                    $errorCount++;
				}

                $tempSalesOrders = $tempSalesOrdersTable->newEntity();
                
                $tempSalesOrders->company_id = $data['company_id'];
                $tempSalesOrders->transaction_total = $data['transaction_total'];
                $tempSalesOrders->transaction_sequence = $data['transaction_sequence'];
                $tempSalesOrders->visit_number = $data['visit_number'];
                $tempSalesOrders->system_id = $form['sales_order_number'];
                $tempSalesOrders->po_number = $data['po_number'];
                $tempSalesOrders->po_cancel_date = isset($form['po_cancel_date']) ?  $form['po_cancel_date'] : date('Y-m-d');
                $tempSalesOrders->custom_header = $data['custom_header'];
                $tempSalesOrders->terms_conditions = $data['terms_conditions'];
                $tempSalesOrders->no_of_order_total = $data['no_of_order_total'];
                $tempSalesOrders->tab_user_name = $data['tab_user_name'];
                $tempSalesOrders->tab_user_email = $data['tab_user_email'];
                $tempSalesOrders->lp_as_of = $data['lp_as_of'];
                $tempSalesOrders->branch_code = $data['branch_code'];
                $tempSalesOrders->branch_name = $data['branch_name'];
                $tempSalesOrders->client_account_code = $data['account_code'];
                $tempSalesOrders->account_name = $data['account_name'];
                $tempSalesOrders->sub_w_tax_total = $data['total_with_tax'];
                $tempSalesOrders->sub_w_out_tax_total = $data['total_without_tax'];
                $tempSalesOrders->actual_delivery_date = ($data['actual_delivery_date'] == null ? '0000-00-00' : $data['actual_delivery_date']);
                $tempSalesOrders->integration_transaction_id = ($data['integration_transaction_id'] == null ? 0 : $data['integration_transaction_id']);
                $tempSalesOrders->delivery_date = $data['requested_delivery_date'];
                $tempSalesOrders->order_protection = $data['order_protection'];
                $tempSalesOrders->deleted = $data['deleted'];
                $tempSalesOrders->integration_transaction_id = $data['integration_transaction_id'];
                $tempSalesOrders->order_status = $data['order_status'];
                $tempSalesOrders->merchandising = $data['merchandising'];
                $tempSalesOrders->pricing = $data['pricing'];
                $tempSalesOrders->promo = $data['promo'];
                $tempSalesOrders->counter_created = isset($data['counter_created']) ? $data['counter_created'] : date('Y-m-d');
                $tempSalesOrders->ok_for_picking = $data['ok_for_picking'];
                $tempSalesOrders->priority = $data['priority'];
                $tempSalesOrders->invoice_number = $data['invoice_number'];
                $tempSalesOrders->notes = $data['notes'];
                $tempSalesOrders->ship_location = $data['ship_location'];
                $tempSalesOrders->count_sheet_number = $data['count_sheet_number'];
                $tempSalesOrders->timestamp = $data['timestamp'];
                $tempSalesOrders->anti_fraud_message = $data['anti_fraud_message'];
                $tempSalesOrders->created = $date;
                $tempSalesOrders->modified = $date;

                $tempSalesOrders->original_id = $formId;
                $tempSalesOrders->field_username = $counterpartUsername['code'];
                $tempSalesOrders->from_company_id = $source_company_id;
                $tempSalesOrders->transferred = 0;
                $tempSalesOrders->transferred_id = 0;
                $tempSalesOrders->counterpart_account_code = $counterpartAccountCode['code'];
                $tempSalesOrders->counterpart_branch_code = $counterpartBranchCode['code'];
     
                $doInsert = $tempSalesOrdersTable->save($tempSalesOrders);
				
				if(!$doInsert){ 
                    $errorMessage[] = "Sales Order with Sales order Number : <strong>{$form['sales_order_number']}</strong> cannot be saved.  <br>";
                    $errorCount++;
				}

			}
            
			$hasProductError = false;
            
			$TempSalesOrderFormItem = $this->saveTempSalesOrderItems($salesOrderItems, $source_company_id, $principal_company_id);

			if($TempSalesOrderFormItem['hasError']){
				
                foreach( $TempSalesOrderFormItem['message'] as $value ) {
                    $result['message'][] = $value;
                    $errorCount++;
                }
			}

			$TempBookingApprovalStatus = $this->saveTempBookingApprovalStatus($BookingApprovalStatus, $source_company_id, $principal_company_id, $formId);

			if($TempBookingApprovalStatus['hasError']){
				
                foreach( $TempBookingApprovalStatus['message'] as $value ) {
                    $result['message'][] = $value;
                    $errorCount++;
                }
			}

			$TempBookingApprovalEncharge = $this->saveTempBookingApprovalEncharges($BookingApprovalEncharge, $source_company_id, $principal_company_id);

			if($TempBookingApprovalEncharge['hasError']){

                foreach( $TempBookingApprovalEncharge['message'] as $value ) {
                    $result['message'][] = $value;
                    $errorCount++;
                }
			}
			
			$TempActualInvoice = $this->saveToTempInvoices($ActualInvoice, $source_company_id, $principal_company_id);
         
			if($TempActualInvoice['hasError']){
				
                foreach( $TempActualInvoice['message'] as $value ) {
                    $result['message'][] = $value;
                    $errorCount++;
                }
			}

			$TempActualInvoiceItem = $this->saveToTempInvoiceItems($ActualInvoiceItem, $source_company_id, $principal_company_id);
         
			if($TempActualInvoiceItem['hasError']){
				
                foreach( $TempActualInvoiceItem['message'] as $value ) {
                    $result['message'][] = $value;
                    $errorCount++;
                }
			}

			// if no error just commit
            
		}
        
        $result['errorMessage'] = $errorMessage;
        $result['errorCount'] = $errorCount;
        echo json_encode( $result );
        exit;
    }

    function saveTempBookingApprovalEncharges($records, $source_company_id, $principal_company_id){
		
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempBookingApprovalEnchargesTbl = $this->setSource('temp_booking_approval_encharges', $principal_company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

		foreach ($records as $itemKey => $value) {
			
            $original_id = $value['id'];

			$doExist = $tempBookingApprovalEnchargesTbl->find()
                     ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $original_id,
                        'company_id' => $principal_company_id
                     ])
                     ->first();
			
			if(!$doExist){

                $temp_booking_approve_encharge = $tempBookingApprovalEnchargesTbl->newEntity();

                $temp_booking_approve_encharge->original_id = $original_id;
                $temp_booking_approve_encharge->company_id = $source_company_id;
                $temp_booking_approve_encharge->from_company_id = $principal_company_id;
                $temp_booking_approve_encharge->transferred = 0;
                $temp_booking_approve_encharge->transferred_id = 0;

                $temp_booking_approve_encharge->code = $value['code'];
                $temp_booking_approve_encharge->level = $value['level'];
                $temp_booking_approve_encharge->booking_approval_status_id = $value['booking_approval_status_id'];
                $temp_booking_approve_encharge->agent_user_id = $value['agent_user_id'];
                $temp_booking_approve_encharge->agent_full_name = $value['agent_full_name'];
                $temp_booking_approve_encharge->encharge_user_id = $value['encharge_user_id'];
                $temp_booking_approve_encharge->encharge_full_name = $value['encharge_full_name'];
                $temp_booking_approve_encharge->encharge_mobile_number = $value['encharge_mobile_number'];
                $temp_booking_approve_encharge->booking_id = $value['booking_id'];
                $temp_booking_approve_encharge->booking_system_id = $value['booking_system_id'];
                $temp_booking_approve_encharge->status = $value['status'];
                $temp_booking_approve_encharge->created = $date;
                $temp_booking_approve_encharge->modified = $date;

                if( $tempBookingApprovalEnchargesTbl->save( $temp_booking_approve_encharge ) ) {
                } else {
                    $result['hasError'] = true;
                }

			}

		}
        
		return $result;
	}

	function saveToTempInvoices($records, $source_company_id, $principal_company_id){
        
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempActualInvoiceTbl = $this->setSource('temp_actual_invoices', $principal_company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');
        
		foreach ($records as $itemKey => $item) {
			
            $original_id = $item['id'];

			$doExist = $tempActualInvoiceTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $original_id,
                        'company_id' => $principal_company_id
                    ])
                    ->first();
			
			$accountCode = $item['account_code'];
			$branchCode = $item['branch_code'];
			$warehouseCode = $item['warehouse_code'];
            
			$counterpartAccountCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $accountCode, 1);
			$counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $branchCode, 2);
			$counterpartWarehouseCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $warehouseCode, 3);
           
            $userId = $this->getUserIdByUsername( $item['uploaded_by'], $source_company_id );           
            $invoiceUserId = $this->getUserIdByUsername( $item['invoice_user'], $source_company_id );           

			if($accountCode == 'null' || is_null($accountCode) || $accountCode == null) {
                $result['message'][] = "Account Counterpart Code is null {$accountCode}";
                $result['hasError'] = true;
			}
			if($branchCode == 'null' || is_null($branchCode) || $branchCode == null) {
                $result['message'][] = "Branch Counterpart Code is null {$branchCode}";
                $result['hasError'] = true;
			}
			if($warehouseCode == 'null' || is_null($warehouseCode) || $warehouseCode == null) {
                $result['message'][] = "Warehouse Counterpart Code is null {$warehouseCode}";
                $result['hasError'] = true;
			}

			if(!$doExist){

                $temp_actual_invoice = $tempActualInvoiceTbl->newEntity();

                $temp_actual_invoice->original_id = $original_id;
                $temp_actual_invoice->company_id = (int)$principal_company_id;
                $temp_actual_invoice->from_company_id = $source_company_id;
                $temp_actual_invoice->transferred = 0;
                $temp_actual_invoice->transferred_id = 0;
                $temp_actual_invoice->counterpart_account_code = $counterpartAccountCode['code'];
                $temp_actual_invoice->counterpart_branch_code = $counterpartBranchCode['code'];
                $temp_actual_invoice->counterpart_warehouse_code = $counterpartWarehouseCode['code'];

                $temp_actual_invoice->system_invoice_number = isset($item['system_invoice_number']) ? $item['system_invoice_number'] : "0";
                $temp_actual_invoice->invoice_number = $item['invoice_number'];
                $temp_actual_invoice->invoice_date = $item['invoice_date'];
                $temp_actual_invoice->client_account_code = $item['account_code'];
                $temp_actual_invoice->client_account_name = $item['account_name'];
                $temp_actual_invoice->branch_code = $item['branch_code'];
                $temp_actual_invoice->branch_name = $item['branch_name'];
                $temp_actual_invoice->date_created = $date;
                $temp_actual_invoice->date_modified = $date;
                $temp_actual_invoice->sales_order_number = $item['sales_order_number'];
                $temp_actual_invoice->uploaded_by = $userId;
                $temp_actual_invoice->warehouse_code = $item['warehouse_code'];
                $temp_actual_invoice->warehouse_inventory_deducted = $item['warehouse_inventory_deducted'];
                $temp_actual_invoice->inventory_count_added = $item['inventory_count_added'];
                $temp_actual_invoice->inventory_count_id = $item['inventory_count_id'];
                $temp_actual_invoice->invoice_user_id = $invoiceUserId;
                $temp_actual_invoice->deleted = $item['deleted'];
                
                if( !$tempActualInvoiceTbl->save( $temp_actual_invoice ) ) {
                    $result['message'][]= "Cannot be saved.";
                    $result['hasError'] = true;
                }

			}

		}

        return $result; 
	}

	function saveToTempInvoiceItems($records, $source_company_id, $principal_company_id)
    {
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempActualInvoiceItemsTbl = $this->setSource('temp_actual_invoice_items', $principal_company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
		
        $connections->begin();

		foreach ($records as $itemKey => $item) {
			
			$original_id = $item['id'];

			$doExist = $tempActualInvoiceItemsTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $original_id,
                        'company_id' => $principal_company_id
                    ])
                    ->first();
			
			$productCode = $item['product_code'];
			$productUom = $item['unit_of_measure'];

			$counterpartProductUom = $this->getCounterpartProductUom($source_company_id, $principal_company_id, $productCode, $productUom);
			$counterpartProductCode = $this->getProductCounterPartCodes($source_company_id, $principal_company_id, $productCode, 4);
			
			if($productCode == 'null' || is_null($productCode) || $productCode == null) {
                $result['message'][] = "No Counterpart Code for Product Code : {$productCode}";
                $connections->rollback();
				$counterpartProductCode == 'null';
			}

			if( !$doExist && !$result['hasError'] ){

                $temp_actual_invoice_items = $tempActualInvoiceItemsTbl->newEntity();

                $temp_actual_invoice_items->original_id = $original_id;
                $temp_actual_invoice_items->from_company_id = $source_company_id;
                $temp_actual_invoice_items->company_id = $principal_company_id;
                $temp_actual_invoice_items->transferred = 0;
                $temp_actual_invoice_items->transferred_id = 0;
                $temp_actual_invoice_items->counterpart_product_code = $counterpartProductCode;
                $temp_actual_invoice_items->counterpart_product_uom = $counterpartProductUom;

                $temp_actual_invoice_items->system_invoice_number = $item[''];
                $temp_actual_invoice_items->invoice_number = $item[''];
                $temp_actual_invoice_items->product_code = $item[''];
                $temp_actual_invoice_items->product_name = $item[''];
                $temp_actual_invoice_items->quantity = $item[''];
                $temp_actual_invoice_items->unit_of_measure = $item[''];
                $temp_actual_invoice_items->price = $item[''];
                $temp_actual_invoice_items->date_created = $item[''];
                $temp_actual_invoice_items->date_modified = $item[''];
                $temp_actual_invoice_items->date_modified = $item[''];
                $temp_actual_invoice_items->deleted = $item[''];
                $temp_actual_invoice_items->price_total_amount = $item[''];
                $temp_actual_invoice_items->price_from_csv = $item[''];
                $temp_actual_invoice_items->lot_number = $item[''];
                
                if( $tempActualInvoiceItemsTbl->save( $temp_actual_invoice_items ) ) {
                    $connections->commit();
                } else {
                    $result['hasError'] = true;
                    $result['message'][] = "Cannot be saved";
                    $connections->rollback();
                }

			}

		}

        return $result;
	}


    public function saveTempBookingApprovalStatus($records, $source_company_id, $principal_company_id, $booking_id)
    {
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempBookingApprovalStatusTbl = $this->setSource('temp_booking_approval_status', $principal_company_id); 

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');
        
		foreach ($records as $itemKey => $item) {
			
			$original_id = $item['id'];

            $condition = [
                'from_company_id' => $source_company_id,
                'original_id' => $original_id,
                'company_id' => $principal_company_id
            ];

			$doExist = $tempBookingApprovalStatusTbl->find()->where($condition)->first();
			
			$accountCode = $item['account_code'];
			$branchCode = $item['branch_code'];
			

			$counterpartAccountCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $accountCode, 1);
			$counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $branchCode, 2);

			if($accountCode == 'null' || is_null($accountCode) || $accountCode == null) {
                $result['hasError'] = true;
				$counterpartAccountCode == 'null';
                $result['message'][] = "No Counterpart Account Code Found : {$counterpartAccountCode}";
			}
			if($branchCode == 'null' || is_null($branchCode) || $branchCode == null) {
                $result['hasError'] = true;
				$counterpartBranchCode == 'null';
                $result['message'][] = "No Counterpart Account Code Found : {$counterpartAccountCode}";
			}

			if(!$doExist){

                $temp_booking_approval_status = $tempBookingApprovalStatusTbl->newEntity();

                $temp_booking_approval_status->original_id = $original_id;
                $temp_booking_approval_status->booking_id = $booking_id;
                $temp_booking_approval_status->company_id = $principal_company_id;
                $temp_booking_approval_status->from_company_id = $source_company_id;
                $temp_booking_approval_status->transferred = 0;
                $temp_booking_approval_status->transferred_id = 0;
                $temp_booking_approval_status->counterpart_account_code = $counterpartAccountCode;
                $temp_booking_approval_status->counterpart_branch_code = $counterpartBranchCode;

                $temp_booking_approval_status->agent_user_id = $value['agent_user_id'];
                $temp_booking_approval_status->agent_fullname = $value['agent_fullname'];
                $temp_booking_approval_status->username = $value['username'];
                $temp_booking_approval_status->requested_by_team_leader_id = $value['requested_by_team_leader_id'];
                $temp_booking_approval_status->requested_to_team_leader_id = $value['requested_to_team_leader_id'];
                $temp_booking_approval_status->booking_system_id = $value['booking_system_id'];
                $temp_booking_approval_status->account_code = $value['account_code'];
                $temp_booking_approval_status->branch_code = $value['branch_code'];
                $temp_booking_approval_status->account_name = $value['account_name'];
                $temp_booking_approval_status->branch_name = $value['branch_name'];
                $temp_booking_approval_status->ordered_amount = $value['ordered_amount'];
                $temp_booking_approval_status->od_approval = $value['od_approval'];
                $temp_booking_approval_status->ocl_approval = $value['ocl_approval'];
                $temp_booking_approval_status->td_approval = $value['td_approval'];
                $temp_booking_approval_status->ad_approval = $value['ad_approval'];
                $temp_booking_approval_status->ad_approval = $value['ad_approval'];
                $temp_booking_approval_status->oio_approval = $value['oio_approval'];
                $temp_booking_approval_status->payment_terms = $value['payment_terms'];
                $temp_booking_approval_status->oldest_ar = $value['oldest_ar'];
                $temp_booking_approval_status->bounced_checks_count = $value['bounced_checks_count'];
                $temp_booking_approval_status->ar_over_due_amount = $value['ar_over_due_amount'];
                $temp_booking_approval_status->account_credit_limit = $value['account_credit_limit'];
                $temp_booking_approval_status->vaof_less_collection_and_discount = $value['vaof_less_collection_and_discount'];
                $temp_booking_approval_status->level = $value['level'];
                $temp_booking_approval_status->downloaded = $value['downloaded'];
                $temp_booking_approval_status->modified = $date;
                $temp_booking_approval_status->created = $date;
                
                if( $tempBookingApprovalStatusTbl->save($temp_booking_approval_status) ) {
                } else {
                }
			}

		}

        return $result;
    }	

    public function transferTempBaof()
    {
        $this->autoRender = false;
		$user = $this->Auth->user();
        $company_id = $user['company_id'];
		
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $baof = $this->getAllBaof($start_date, $end_date, $company_id, $index, $selected_principal);
        $savedToBaof = $this->saveToBaof($baof, $company_id, $selected_principal);
    
        
        echo json_encode($savedToBaof);
        // echo $savedToBaof['transferred'];

    }

    public function saveToBaof( $records, $company_id, $selected_principal )
    {
        $selected_principal = (int)$selected_principal;
        $connections = ConnectionManager::get('client_'. $selected_principal );
        $tempSalesOrderFormsTbl  = $this->setSource('tempSalesOrders', $selected_principal);  
        $sales_orders_table_table = TableRegistry::get('SalesOrders', ['connection' => $connections]);
        
        $error = array();
		$error['message'] = [];
		$error['count'] = 0;
		$error['transferred'] = 0;
        $date = date('Y-m-d H:i:s');

        foreach ($records as $key => $data) {
			
            
			$form = $records[0];

			$formId = $data['id'];
			$SalesOrderForm = $data['SalesOrderForm'];
			$original_id = $data['id'];
            $sales_order_number = $data['sales_order_number'];
			$SalesOrderForm['company_id'] = $selected_principal;
			
			$SalesOrderFormItem = $data['SalesOrderItem'];
			$BookingApprovalStatus = $data['BookingApprovalStatus'];
			$BookingApprovalEncharge = $data['BookingApprovalEncharge'];
			$ActualInvoiceItem = $data['ActualInvoiceItem'];
			$ActualInvoice = $data['ActualInvoice'];
            			
			$conditions = array(
				'original_id' => $original_id,
				'from_company_id' => $company_id,
				'company_id' => $selected_principal,
				'transferred' => 0
			);

			$has_existing_temp = $tempSalesOrderFormsTbl->find()
                        ->where($conditions)
                        ->first();
                        
			if( $has_existing_temp ){

				$counterpart_account_code = $has_existing_temp['counterpart_account_code'];
				$branch_branch_code = $has_existing_temp['counterpart_branch_code'];
                $sales_order_number = $has_existing_temp['system_id'];
                $product_group_code = !empty($has_existing_temp['product_group_code']) ? $has_existing_temp['product_group_code'] : "None";
                
                $principal_sales_orders_cond = [
                    'sales_order_number' => $sales_order_number,
                    'company_id' => $selected_principal
                ];

                $sales_orders_transfer = $sales_orders_table_table->newEntity();

                $sales_orders_transfer->company_id = $selected_principal;
                $sales_orders_transfer->branch_code = $branch_branch_code;
                $sales_orders_transfer->transaction_total = $has_existing_temp['transaction_total'];
                $sales_orders_transfer->transaction_sequence = $has_existing_temp['transaction_sequence'];
                $sales_orders_transfer->visit_number = $has_existing_temp['visit_number'];
                $sales_orders_transfer->sales_order_number = $sales_order_number;
                $sales_orders_transfer->po_number = $has_existing_temp['po_number'];
                $sales_orders_transfer->po_cancel_date = $has_existing_temp['po_cancel_date'];
                $sales_orders_transfer->username = $has_existing_temp['field_username'];
                $sales_orders_transfer->custom_header = $has_existing_temp['custom_header'];
                $sales_orders_transfer->terms_conditions = $has_existing_temp['terms_conditions'];
                $sales_orders_transfer->no_of_order_total = $has_existing_temp['no_of_order_total'];
                $sales_orders_transfer->tab_user_name = $has_existing_temp['tab_user_name'];
                $sales_orders_transfer->tab_user_email = $has_existing_temp['tab_user_email'];
                $sales_orders_transfer->lp_as_of = $has_existing_temp['lp_as_of'];
                $sales_orders_transfer->product_group_code = $has_existing_temp['product_group_code'];
                $sales_orders_transfer->total_with_tax = $has_existing_temp['sub_w_tax_total'];
                $sales_orders_transfer->total_without_tax = $has_existing_temp['sub_w_out_tax_total'];
                $sales_orders_transfer->requested_delivery_date = $has_existing_temp['delivery_date'];
                $sales_orders_transfer->actual_delivery_date = $has_existing_temp['actual_delivery_date'] == null ? '0000-00-00' : $has_existing_temp['actual_delivery_date'];
                $sales_orders_transfer->integration_transaction_id = $has_existing_temp['integration_transaction_id'] == null ? '0000-00-00' : $has_existing_temp['integration_transaction_id'];
                $sales_orders_transfer->order_protection = $has_existing_temp['order_protection'];
                $sales_orders_transfer->deleted = $has_existing_temp['deleted'];
                $sales_orders_transfer->integration_transaction_id = $has_existing_temp['integration_transaction_id'];
                $sales_orders_transfer->order_status = $has_existing_temp['order_status'];
                $sales_orders_transfer->merchandising = $has_existing_temp['merchandising'];
                $sales_orders_transfer->pricing = $has_existing_temp['pricing'];
                $sales_orders_transfer->promo = $has_existing_temp['promo'];
                $sales_orders_transfer->counter_created = $has_existing_temp['counter_created'];
                $sales_orders_transfer->ok_for_picking = $has_existing_temp['ok_for_picking'];
                $sales_orders_transfer->priority = $has_existing_temp['priority'];
                $sales_orders_transfer->invoice_number = $data['invoice_number'];
                $sales_orders_transfer->notes = $has_existing_temp['notes'];
                $sales_orders_transfer->ship_location = $has_existing_temp['ship_location'];
                $sales_orders_transfer->count_sheet_number = $has_existing_temp['count_sheet_number'];
                $sales_orders_transfer->netsuite_transaction_id = $has_existing_temp['netsuite_transaction_id'];
                $sales_orders_transfer->timestamp = $has_existing_temp['timestamp'];
                $sales_orders_transfer->anti_fraud_message = $has_existing_temp['anti_fraud_message'];
                $sales_orders_transfer->has_image = $has_existing_temp['has_image'];
                $sales_orders_transfer->image_filename = $has_existing_temp['image_filename'];
                $sales_orders_transfer->print_for_delivery = $has_existing_temp['print_for_delivery'];

                $sales_orders_transfer->warehouse_code = !empty($has_existing_temp['warehouse_code']) ? $has_existing_temp['warehouse_code'] : 0;
                $sales_orders_transfer->account_name = $this->getBranchNameByBranchCode( $branch_branch_code, $selected_principal );
                $sales_orders_transfer->branch_name = $this->getAccountNameByAccountCode( $counterpart_account_code, $selected_principal );
                $sales_orders_transfer->product_group_code = $product_group_code;
                $sales_orders_transfer->account_code = $counterpart_account_code;
                $sales_orders_transfer->order_protection = 0;
                $sales_orders_transfer->has_image = 0;
                $sales_orders_transfer->image_filename = 0;
                $sales_orders_transfer->print_for_delivery = 0;
                $sales_orders_transfer->created = $date;
                $sales_orders_transfer->modified = $date;
                
                $save_sales_orders = $sales_orders_table_table->save($sales_orders_transfer);

                if( !$save_sales_orders ) {
                    $error['message'][] = "Sales Order with sales order number {$sales_order_number} cannot be saved.";
                    $error['count']++;
                } else {
                    $error['count']++;
                }

				//update temp table transferred column and date
				$booking_transferred_id = $save_sales_orders['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $booking_transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'company_id' => $selected_principal, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$tempSalesOrderFormsTbl->updateAll($updateSet, $updateCondition)) { 
					$error['count']++; 
					continue;
				}
				
				// save all items

                $transfer_sales_order_items = $this->save_sales_order_item($SalesOrderFormItem, $company_id, $selected_principal, $counterpart_account_code, $branch_branch_code);

				if( !$transfer_sales_order_items ){

					$error['count']++; 
					continue;

				}
                
                $transferBookingApprovalStatus = $this->save_booking_approval_status($BookingApprovalStatus, $company_id, $selected_principal, $booking_transferred_id);

				if( $transferBookingApprovalStatus['counter'] != 0 ){

                    foreach( $transferBookingApprovalStatus['message'] as $value ) {
                        $error['message'][] = $value;
                        $error['count']++; 
                    }

					continue;

				}

                $transferBookingApprovalEncharnges = $this->save_booking_approval_encharges($BookingApprovalEncharge, $company_id, $selected_principal, $booking_transferred_id);
           
				if( $transferBookingApprovalEncharnges['counter'] != 0 ){

                    foreach( $transferBookingApprovalEncharnges['message'] as $value ) {
                        $error['message'][] = $value;
                        $error['count']++; 
                    }

					continue;

				}

                $transferSaveActualInvoices = $this->save_actual_invoice($ActualInvoice, $company_id, $selected_principal);

				if( $transferSaveActualInvoices['counter'] != 0 ){

                    foreach( $transferSaveActualInvoices['message'] as $value ) {
                        $error['message'][] = $value;
                        $error['count']++; 
                    }

					continue;

				}

                $transferSaveActualnvoiceItem = $this->save_actual_invoice_item($ActualInvoiceItem, $company_id, $selected_principal);

				if( $transferSaveActualnvoiceItem['counter'] != 0 ){

                    foreach( $transferSaveActualnvoiceItem['message'] as $value ) {
                        $error['message'][] = $value;
                        $error['count']++; 
                    }

					continue;

				}

				// return error count

                $error['transferred']++;
			}	 	

		}

		return $error;

	}

	function save_sales_order_item($records, $source_company_id, $company_id, $account_code, $branch_code)
    {
        $connections = ConnectionManager::get('client_'. $company_id );
        $temp_sales_order_items_table = $this->setSource('temp_sales_order_items', $company_id );
        $sales_order_items_table = $this->setSource('SalesOrderItems', $company_id );
   
        $result = [];
        $result['message'] = [];
        $result['counter'] = 0;
        $date = date('Y-m-d H:i:s');

		foreach ( $records as $itemKey => $item ) {
			
			$original_id = $item['id'];

            $doExist = $temp_sales_order_items_table->find()
                        ->where([
                            'from_company_id' => $source_company_id,
                            'original_id' => $original_id,
                            'transferred' => 0
                        ])
                        ->first();
                           
			if($doExist){

                $sales_order_items = $sales_order_items_table->newEntity();

                $sales_order_items->company_id = $company_id;
                $sales_order_items->sales_order_number = $doExist['system_id'];
                $sales_order_items->order_quantity = $doExist['no_of_order'];
                $sales_order_items->product_code = $doExist['counterpart_product_code'];
                $sales_order_items->product_name = $doExist['product_name'];
                $sales_order_items->past_three_sales_ave = $doExist['past_three_sales_ave'];
                $sales_order_items->past_month_sales = $doExist['past_month_sales'];
                $sales_order_items->uom = $doExist['counterpart_product_uom'];
                $sales_order_items->price_with_tax = $doExist['w_tax'];
                $sales_order_items->price_without_tax = $doExist['w_out_tax'];
                $sales_order_items->total_price_with_tax = $doExist['sub_w_tax'];
                $sales_order_items->total_price_without_tax = $doExist['sub_w_out_tax'];
                $sales_order_items->suggested_order = $doExist['suggested_order'];
                $sales_order_items->past_six_sales_ave = $doExist['past_six_sales_ave'];
                $sales_order_items->sa_sw = $doExist['sa_sw'];
                $sales_order_items->distribution = $doExist['distribution'];
                $sales_order_items->availability = $doExist['availability'];
                $sales_order_items->trade_inventory = $doExist['trade_inventory'];
                $sales_order_items->stock_availability = $doExist['stock_availability'];
                $sales_order_items->stock_weight = $doExist['stock_weight'];
                $sales_order_items->picked = $doExist['picked'];
                $sales_order_items->conversion_value = !is_null($doExist['conversion_value']) ? $doExist['conversion_value'] : 0;
                $sales_order_items->promo_authorization_number = !is_null($doExist['promo_authorization_number']) ? $doExist['promo_authorization_number'] : 0;
                $sales_order_items->is_downloaded = !is_null($doExist['is_downloaded']) ? $doExist['is_downloaded'] : 0;
                $sales_order_items->modified = $date;
                $sales_order_items->created = $date;
                
                if( !$sales_order_items_table->save($sales_order_items) ) {
                    $result['message'][] = "Sales Order Item with Sales Order Number {$doExist['system_id']} Cannot be Saved.";
                    $result['counter']++;
                }

				$transferred_id = $doExist['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);

                if( !$temp_sales_order_items_table->updateAll( $updateSet, $updateCondition) ) {
                    $result['message'][] = "Temp Sales Order Items with Original ID {$original_id} cannot be updated.";
                    $result['counter']++;
                }
                
			}else{
                $result['message'][] = "Temp Sales Order Items with Original ID {$original_id} cannot be updated.";
                $result['counter']++;
			}

		}

		return $result;
	}

	function save_booking_approval_status($records, $source_company_id, $company_id, $booking_id){

        $connections = ConnectionManager::get('client_'. $company_id );
        $temp_booking_approval_status_table = $this->SetSource('temp_booking_approval_status', $company_id );
        $booking_approval_status_table = $this->SetSource('BookingApprovalStatuses', $company_id );


        $result = [];
        $result['counter'] = 0;
        $result['message'] = [];
        $date = date('Y-m-d H:i:s');

		foreach ( $records as $itemKey => $item ) {
			
            $original_id = $item['id'];
            
            $temp_booking_approval_status_cond = [
                'from_company_id' => $source_company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExists = $temp_booking_approval_status_table->find()->where($temp_booking_approval_status_cond)->first();
  
			if( $doExist ){

                $booking_approval_status = $booking_approval_status_table->newEntity();

                $booking_approval_status->company_id = $company_id;
                $booking_approval_status->account_code = $doExists['counterpart_account_code'];
                $booking_approval_status->branch_code = $doExists['counterpart_branch_code'];
                $booking_approval_status->agent_fullname = $doExists['agent_fullname'];
                $booking_approval_status->agent_user_id	 = $doExists['agent_user_id	'];
                $booking_approval_status->username = $doExists['username'];
                $booking_approval_status->requested_by_team_leader_id = $doExists['requested_by_team_leader_id'];
                $booking_approval_status->requested_to_team_leader_id = $doExists['requested_to_team_leader_id'];
                $booking_approval_status->booking_id = $doExists['booking_id'];
                $booking_approval_status->booking_system_id = $doExists['booking_system_id'];
                $booking_approval_status->account_name = $doExists['account_name'];
                $booking_approval_status->branch_name = $doExists['branch_name'];
                $booking_approval_status->ordered_amount = $doExists['ordered_amount'];
                $booking_approval_status->od_approval = $doExists['od_approval'];
                $booking_approval_status->ocl_approval = $doExists['ocl_approval'];
                $booking_approval_status->td_approval = $doExists['td_approval'];
                $booking_approval_status->ad_approval = $doExists['ad_approval'];
                $booking_approval_status->po_approval = $doExists['po_approval'];
                $booking_approval_status->oio_approval = $doExists['oio_approval'];
                $booking_approval_status->bc_approval = $doExists['bc_approval'];
                $booking_approval_status->payment_terms = $doExists['payment_terms'];
                $booking_approval_status->oldest_ar = $doExists['oldest_ar'];
                $booking_approval_status->bounced_checks_count = $doExists['bounced_checks_count'];
                $booking_approval_status->ar_over_due_amount = $doExists['ar_over_due_amount'];
                $booking_approval_status->account_credit_limit = $doExists['account_credit_limit'];
                $booking_approval_status->vaof_less_collection_and_discount	 = $doExists['vaof_less_collection_and_discount'];
                $booking_approval_status->level = $doExists['level'];
                $booking_approval_status->requested_date = $doExists['requested_date'];
                $booking_approval_status->downloaded = $doExists['downloaded'];
                $booking_approval_status->created = $date;
                $booking_approval_status->modified = $date;

                
                if( !$booking_approval_status_table->save($booking_approval_status) ) {

                    $result['message'][] = "Booking Status Approval With ID {$original_id} cannot be transfered.";
                    $result['counter']++;

                } else {

                    $transferred_id = $booking_approval_status['id'];
                    $updateSet = array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $transferred_id);
                    $updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);

                    if( !$temp_booking_approval_status_table->updateAll( $updateSet, $updateCondition ) ) {
                        $result['message'][] = "Temp Booking Status Approval With original ID {$original_id} cannot be transfered.";
                        $result['counter']++;
                    } 
                    
                }


			}else{
                $result['counter']++;
                $result['message'][] = "Booking approval status cannot be transfered because Sales Order";
			}

		}

		return $result;
	}

	function save_booking_approval_encharges($records, $source_company_id, $company_id, $booking_id){

        $connections = ConnectionManager::get('client_'. $company_id );
        $temp_booking_aproval_echarges_tbl = $this->setSource('temp_booking_approval_encharges', $company_id);
        $booking_approval_encharges_tbl = $this->setSource('BookingApprovalEncharges', $company_id);    

        $result = [];
        $result['message'] = [];
        $result['counter'] = 0;
        $date = date('Y-m-d H:i:s');

		foreach ($records as $itemKey => $item) {
			
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $source_company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];
            
            $doExists = $temp_booking_aproval_echarges->find()->where($cond)->first();
			
			if( $doExist ){

                $booking_approval_encharges = $booking_approval_encharges_tbl->newEntity();

                $booking_approval_encharges->company_id = $company_id;
                $booking_approval_encharges->code = $doExist['code'];
                $booking_approval_encharges->level = $doExist['level'];
                $booking_approval_encharges->booking_approval_status_id = $doExist['booking_approval_status_id'];
                $booking_approval_encharges->agent_user_id = $doExist['agent_user_id'];
                $booking_approval_encharges->agent_full_name = $doExist['agent_full_name'];
                $booking_approval_encharges->encharge_user_id = $doExist['encharge_user_id'];
                $booking_approval_encharges->encharge_full_name = $doExist['encharge_full_name'];
                $booking_approval_encharges->encharge_mobile_number = $doExist['encharge_mobile_number'];
                $booking_approval_encharges->booking_id = $doExist['booking_id'];
                $booking_approval_encharges->booking_system_id = $doExist['booking_system_id'];
                $booking_approval_encharges->status = $doExist['status'];
                $booking_approval_encharges->created = $date;
                $booking_approval_encharges->modified = $date;

                if( !$booking_approval_encharges_tbl->save($booking_approval_encharges) ) {
                    
                    $result['message'][] = "Booking Approval Encharges with Booking System ID {$doExist['booking_system_id']} cannot be Saved. ";
                    $result['counter']++;

                } else {

                    $transferred_id = $booking_approval_encharges['id'];
                    $updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
                    $updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);

                    if( !$temp_booking_aproval_echarges_tbl->updateAll( $updateSet, $updateCondition ) ) {

                        $result['message'][] = "Temp Booking Approval Encharges with original ID {$original_id} Cannot be Updated. ";
                        $result['counter']++;

                    }

                }

			}else{
                $result['message'][] = "Booking Approval Encharges with original ID {$original_id} is not exists in Temp. ";
                $result['counter']++;
			}

		}

		return $result;
	}

	function save_actual_invoice($records, $source_company_id, $company_id){

        $connections = ConnectionManager::get('client_'. $company_id );
        $temp_invoices_table = $this->setSource('temp_actual_invoices', $company_id );
        $invoices_table = $this->setSource('Invoices', $company_id);

        $result = [];
        $result['message'] = [];
        $result['counter'] = 0;
        $date = date('Y-m-d H:i:s');

		foreach ($records as $itemKey => $item) {
			
            $original_id = $item['id'];

            $cond = [
                'from_company_id' => $source_company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];
            
            $doExist = $temp_invoices_table->find()->where( $cond )->first();
            
			if($doExist){

                $invoices = $invoices_table->newEntity();

                $invoices->company_id = $company_id;
                $invoices->sales_order_number = $item['sales_order_number'];
                $invoices->invoice_number = $item['invoice_number'];
                $invoices->invoice_user =  $this->getCounterpartUsers( $source_company_id, $company_id, $item['invoice_user'] );
                $invoices->invoice_date = $item['invoice_date'];
                $invoices->inventory_count_added = $item['inventory_count_added'];
                $invoices->inventory_count_id = $item['inventory_count_id'];
                $invoices->branch_code = $doExist['counterpart_branch_code'];
                $invoices->branch_name = $item['branch_name'];
                $invoices->account_code = $doExist['counterpart_account_code'];
                $invoices->account_name = $item['account_name'];
                $invoices->warehouse_code = $doExist['counterpart_warehouse_code'];
                $invoices->warehouse_inventory_deducted = $item['warehouse_inventory_deducted'];
                $invoices->current_invoice_status = $item['current_invoice_status'];
                $invoices->uploaded_by = $this->getCounterpartUsers( $source_company_id, $company_id, $item['uploaded_by'] );
                $invoices->trans_type = $item['trans_type'];
                $invoices->deleted = $item['deleted'];
                $invoices->created = $date;
                $invoices->modified = $date;
                
                $save_to_invoices = $invoices_table->save( $invoices );

                if( $save_to_invoices ) {

                    $transferred_id = $save_to_invoices['id'];
                    $updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
                    $updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);

                    if( !$temp_invoices_table->updateAll( $updateSet, $updateCondition ) ) {
                        $result['message'][] = "Temp Invoices with Original ID {$original_id} cannot be updated.";
                        $result['counter']++;
                    }

                } else {
                    $result['message'][] = "Invoices with Invoices Number {$item['invoice_number']} And Sales Order Number {$item['sales_order_number']} cannot be saved.";
                    $result['counter']++;
                }


			}else{
                $result['message'][] = "Temp Invoices with Original ID {$original_id} is not Exists.";
                $result['counter']++;
			}

		}

		return $result;
	}

	function save_actual_invoice_item($records, $source_company_id, $company_id){

        $connections = ConnectionManager::get('client_'. $company_id );
        $temp_invoices_items_table = $this->setSource('temp_actual_invoice_items', $company_id);
        $invoices_items_table = $this->setSource('InvoiceItems', $company_id);

        $result = [];
        $result['message'] = [];
        $result['counter'] = 0;
        $date = date('Y-m-d H:i:s');


		foreach ($records as $itemKey => $item) {
			
			$original_id = $item['id'];
			
            $cond = [
                'from_company_id' => $source_company_id,
                'original_id' => $itemId,
                'transferred' => 0
            ];

			$doExist = $temp_invoices_items_table->find()->where($cond)->first();

			if( $doExist ){

				$invoices_items = $temp_invoices_items_table->newEntity();

                $invoices_items->company_id = $company_id;
                $invoices_items->sales_order_number = $item['sales_order_number'];
                $invoices_items->invoice_number = $item['invoice_number'];
                $invoices_items->product_code = $item['counterpart_product_code'];
                $invoices_items->product_name = $item['product_name'];
                $invoices_items->quantity_invoiced = $item['quantity_invoiced'];
                $invoices_items->quantity_received = $item['quantity_received'];
                $invoices_items->uom = $item['counterpart_product_uom'];
                $invoices_items->price_with_tax = $item['price_with_tax'];
                $invoices_items->price_without_tax = $item['price_without_tax'];
                $invoices_items->price_without_tax = $item['price_without_tax'];
                $invoices_items->total_price_with_tax = $item['total_price_with_tax'];
                $invoices_items->total_price_without_tax = $item['total_price_without_tax'];
                $invoices_items->price_from_csv = $item['price_from_csv'];
                $invoices_items->price_total_amount = $item['price_total_amount'];
                $invoices_items->lot_number = $item['lot_number'];
                $invoices_items->branch_code = $item['branch_code'];
                $invoices_items->branch_name = $item['branch_name'];  
                $invoices_items->account_code = $item['account_code'];  
                $invoices_items->account_name = $item['account_name'];  
                $invoices_items->created = $date;  
                $invoices_items->modifed = $date;  

                $save_invoice_items = $temp_invoices_items_table->save( $invoices_items );

                if( $save_invoice_items ) {

                    $transferred_id = $save_invoice_items['id'];
                    $updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
                    $updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);

                    if( !$temp_invoices_items_table->updateAll( $updateSet, $updateCondition ) ) {
                        $result['message'][] = "Temp Invoice Items With Original ID {$original_id} cannot updated.";
                        $result['counter']++;
                    }
                } else {
                    
                    $result['message'][] = "Invoice Items With With Sales Order Number {$item['sales_order_number']} Cannot be saved.";
                    $result['counter']++;
                }


			}else{
                $result['message'][] = "Invoice Items With With Sales Order Number {$item['sales_order_number']} Cannot be saved.";
                $result['counter']++;
			}

		}

		return $result;
	}

    public function pushDataSBToTemp()
    {
        $this->autoRender = false;
		$user = $this->Auth->user();
        $company_id = $user['company_id'];

        
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');


        $schedules = $this->getAllScheduledBranches($start_date, $end_date, $company_id, $index);
        
        $saveToTemp = $this->saveSchedulesToTemp($schedules, $company_id, $selected_principal);
       
        echo json_encode($saveToTemp);	
    }

    public function saveSchedulesToTemp($schedules, $source_company_id, $company_id){
		
        $SchedulesTbl = $this->setSource('Schedules', $company_id);
        $tempSchedulesTbl = $this->setSource('temp_schedules', $company_id);

		$error = array();
		$errorMessage = array();
		$errorCount = 0;
		$errorBranch = array();
		$errorUser = array();
		
		foreach ($schedules as $key => $information) {
						
			$conditions = array(
				'original_id' => $information['id'],
				'schedule_reference_id' => $information['schedule_reference_id'],
				'from_company_id' => $source_company_id,
				'company_id' => $company_id,
			);

			$doExist = $tempSchedulesTbl->find()
                        ->where($conditions)
                        ->first();

		
			$scheduled_branch_id = $information['id'];
			$branchCode = trim($information['branch_code']);
			$user_id = $information['user_id'];
            
            $defaultConn = ConnectionManager::get('default');
            $usersTbl = TableRegistry::get('users', ['connection' => $defaultConn]);

            $users = $this->getUsersByUserId($user_id, $usersTbl);
           
			$counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $company_id, $branchCode, 2); 
			$counterpartUsername = '';
			$counterpartUserId = 0;
			$counterpartBranchId = 0;
            
			if(!$users){ 
			
				array_push($errorUser, $user_id);
				array_push($errorMessage, "No username for : " . $user_id); 
		
				$errorCount++;
				continue; 
			} else {
				$counterpartUserId = $this->getCounterpartCodes($source_company_id, $company_id, $users['username'], 10);  
			}

			// skip this iteration
			if($counterpartBranchCode == ""){ 
				
			
				array_push($errorBranch, $branchCode);
				array_push($errorMessage, "No code mapping for Branch code : " . $branchCode); 
			
				$errorCount++;
				continue; 
			}else{

                $branchesTbl = $this->setSource('Branches', $company_id); // PrincipalTBl

                $branch = $this->getBranches( $company_id, trim($counterpartBranchCode['code']), 0, $branchesTbl );
        
				if ($branch) {
					$counterpartBranchId = $branch['id'];
				} else {
					array_push($errorBranch, $branchCode);
					array_push($errorMessage, "No code mapping for Branch code : " . $branchCode); 
			
					$errorCount++;
					continue; 
				}

			}
			
			// skip this iteration
			if($counterpartUserId == ""){ 
				
				array_push($errorUser, $user_id);
				array_push($errorMessage, "No code mapping for User ID : " . $user_id); 
			
				$errorCount++;
				continue; 
			}
			

			unset($information['id']);
			$information['company_id'] = $company_id;
			
			if(!$doExist){

                $TempScheduledBranch = $tempSchedulesTbl->newEntity();

				$TempScheduledBranch->create();
				foreach ($information['ScheduledBranch'] as $key => $value) {
					$TempScheduledBranch->set($key, $value);
				}

                $TempScheduledBranch->original_id = $scheduled_branch_id;
                $TempScheduledBranch->from_company_id = $source_company_id;
                $TempScheduledBranch->transferred = 0;
                $TempScheduledBranch->transferred_id = 0;
                $TempScheduledBranch->counterpart_branch_code = $counterpartBranchCode;
                $TempScheduledBranch->counterpart_branch_id = $counterpartBranchId;
                $TempScheduledBranch->counterpart_user_id = $counterpartUserId;

                $doInsert = $tempSchedulesTbl->save($TempScheduledBranch);
				
				if(!$doInsert){ 
					$errorCount++;
					continue ;
				}

			}

		}

		if(count($errorBranch) > 0){
			$err = join(", ", array_unique($errorBranch));
			array_push($errorMessage, "No code mapping for Branch code : " . $err);
		}

		if(count($errorUser) > 0){
			$err = join(", ", array_unique($errorUser));
			array_push($errorMessage, "No code mapping for User ID : " . $err);
		}

		if(count($errorMessage) > 0){
			$error['message'] = join("<br>", $errorMessage);
		}		
		$error['count'] = $errorCount;
		return $error;	
		

	}

    function transferTempSB(){

		// transfer temporary data to actual table
		$this->autoRender = false;
		$user = $this->Auth->user();
        $company_id = $user['company_id'];
	
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $scheduled_branches = $this->getAllScheduledBranches($start_date, $end_date, $company_id, $index);
        $savedToSb = $this->save_to_sb($scheduled_branches, $company_id, $selected_principal);
        
        echo json_encode($savedToSb['transferred']);


	}

    function save_to_sb($scheduled_branches, $source_company_id, $company_id){
		$error = array();
		$error['message'] = '';
		$error['count'] = 0;
		$error['transferred'] = 0;
		
        $branchesTbl = $this->setSource('Branches', $company_id);
        $tempSchedulesTbl = $this->setSource('TempSchedules', $company_id);;

		foreach ($scheduled_branches as $key => &$information) {
		
			$scheduled_branch_id = $information['id'];
			unset($information['id']);
			$information['company_id'] = $company_id;


			$conditions = array(
				'original_id' => $scheduled_branch_id,
				'schedule_reference_id' => $information['schedule_reference_id'],
				'from_company_id' => $source_company_id,
				'transferred' => 0
			);

			$doInformationExist = $tempSchedulesTbl->find()
                                ->where($conditions)
                                ->first();
                                
			if($doInformationExist){
				$this->ScheduledBranch->create();
				foreach ($information as $key => $value) {
					
					switch ($key) {
						case 'branch_code':
							$branch_code = $doInformationExist['TempScheduledBranch']['counterpart_branch_code'];
							$this->ScheduledBranch->set($key, $branch_code);
							break;

						case 'branch_id':
							$branch_id = $doInformationExist['TempScheduledBranch']['counterpart_branch_id'];
							$this->ScheduledBranch->set($key, $branch_id);
							break;

						case 'tab_user_id':
							$user_id = $doInformationExist['TempScheduledBranch']['counterpart_user_id'];
							$this->ScheduledBranch->set($key, $user_id);
							break;

						default:
							$this->ScheduledBranch->set($key, $value);
							break;
					}

				}
				if(!$this->ScheduledBranch->save()){ 
					$error['count']++; 
					continue;
				}

				//update temp table transferred column and date
				$transferred_id = $this->ScheduledBranch->getInsertID();
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				if(!$this->TempScheduledBranch->updateAll($updateSet, $conditions)) { 
					$error['count']++; 
					continue;
				}
				$error['transferred']++;

			}			

		}

		return $error;

	}

    public function countScheduledBranches(){

		$this->autoRender = false;
		$user = $this->Auth->user();
		$company_id = $user['company_id'];

        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';

        $multiPrincipalUserSettingsTbl = $this->setSource('multi_principal_user_settings', $company_id);
        $schedulesTbl = $this->setSource('schedules', $company_id);

		$user_ids = $multiPrincipalUserSettingsTbl->getAllUserIds($company_id);

		$cond = array(
			'schedules.company_id' => $company_id,
			"schedules.scheduled_date BETWEEN '" . $startDate . "' AND '" . $endDate . "'",
			'schedules.deleted_status' => 0,
			'schedules.user_id IN ' => $user_ids
			);


        $schedules = $schedulesTbl->find()
                   ->where($cond)
                   ->count();

        echo $schedules;

	}


    public function getBranches($companyId, $code, $stat, $branchesTbl)
    {
        $branches = $branchesTbl->find()
                ->where([
                    'company_id' => $companyId,
                    'code' => $code,
                    'deleted' => $stat
                ])
                ->first();

        return isset($branches) ? $branches : [];       
    }

    public function getUsersByUserId( $user_id, $usersTbl )
    {
        $users = $usersTbl->find()
                ->where([
                    'id' => $user_id
                ])
                ->first();

        return isset($users) ? $users : [];       
    }

    public function getAllScheduledBranches($start_date, $end_date, $company_id, $current_page){

        $schedulesTbl = $this->setSource('schedules', $company_id);
        $multiPrincipalUserSettingsTbl = $this->setSource('multi_principal_user_settings', $company_id);
        
		
		$user_ids = $multiPrincipalUserSettingsTbl->getAllUserIds($company_id);

		$cond = array(
			'schedules.company_id' => $company_id,
			"schedules.scheduled_date BETWEEN '" . $start_date . "' AND '" . $end_date . "'",
			'schedules.deleted_status' => 0,
			'schedules.user_id IN ' => $user_ids
			);


        $schedules = $schedulesTbl->find()
                   ->where($cond)
                   ->offset($current_page)
                   ->limit(1)
                   ->toArray();
                   
		return $schedules;		

	}

    public function saveTempSalesOrderItems($items, $source_company_id, $principal_company_id)
    {
        $connections = ConnectionManager::get('client_' . $principal_company_id);
        $tempSalesOrderItems = TableRegistry::get('temp_sales_order_items',['connection' => $connections]);
        
        $error = array();
        $errorProduct = array();
		$hasError = false;
		$message = "";

        $sales_order_items = $items;
         
        foreach( $sales_order_items as $key => $value ) {

            $sales_order_item_id = $value['id'];
            
            $cond = [
                'from_company_id' => $source_company_id,
                'original_id' => $sales_order_item_id,
                'company_id' => $principal_company_id
            ];

			$doExist = $tempSalesOrderItems->find()->where($cond)->first();

            $product_code = $value['product_code'];
            $product_uom = $value['uom'];
            
			$counterpartProductUom = $this->getCounterpartProductUom($source_company_id, $principal_company_id, $product_code, $product_uom);
			$counterpartProductCode = $this->getProductCounterPartCodes($source_company_id, $principal_company_id, $product_code );
			// $counterpartProductCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $product_code, 4);
        
			if($product_code == 'null' || is_null($product_code) || $product_code == null) {
                $errorProduct[] = "Sales Order With Sales Order Number {$value['sales_order_number']} has empty product code. <br>";
                $hasError = true; 
                $connections->rollback();
			}
            
			if(!$doExist && !$hasError ){

                $temp_sales_order_items = $tempSalesOrderItems->newEntity();
                
                $temp_sales_order_items->company_id = $principal_company_id;
                $temp_sales_order_items->from_company_id = $source_company_id;
                $temp_sales_order_items->system_id = $value['sales_order_number'];
                $temp_sales_order_items->client_account_id = $sales_order_item_id;
                $temp_sales_order_items->branch_id = $sales_order_item_id;
                $temp_sales_order_items->item_code = $value['product_code'];
                $temp_sales_order_items->product_name = $this->getProductNameByProductCode( $counterpartProductCode, $principal_company_id );
                $temp_sales_order_items->product_id = 0;
                $temp_sales_order_items->past_six_sales_ave = $value['past_six_sales_ave'];
                $temp_sales_order_items->past_three_sales_ave = $value['past_three_sales_ave'];
                $temp_sales_order_items->past_month_sales = $value['past_month_sales'];
                $temp_sales_order_items->unit = $value['uom'];
                $temp_sales_order_items->w_tax = $value['price_with_tax'];
                $temp_sales_order_items->w_out_tax = $value['price_without_tax'];
                $temp_sales_order_items->no_of_order = $value['order_quantity'];
                $temp_sales_order_items->suggested_order = $value['suggested_order'];
                $temp_sales_order_items->sub_w_tax = $value['total_price_with_tax'];
                $temp_sales_order_items->sub_w_out_tax = $value['total_price_without_tax'];
                $temp_sales_order_items->sa_sw = $value['sa_sw'];
                $temp_sales_order_items->distribution = $value['distribution'];
                $temp_sales_order_items->availability = $value['availability'];
                $temp_sales_order_items->trade_inventory = $value['trade_inventory'];
                $temp_sales_order_items->stock_availability = $value['stock_availability'];
                $temp_sales_order_items->stock_weight = $value['stock_weight'];
                $temp_sales_order_items->created = $value['created'];
                $temp_sales_order_items->modified = $value['modified'];
                $temp_sales_order_items->picked = $value['picked'];
                $temp_sales_order_items->transferred = 0;
                $temp_sales_order_items->transferred_id = 0;
                $temp_sales_order_items->original_id = $sales_order_item_id;
                $temp_sales_order_items->transferred_date = "0000-00-00";
                $temp_sales_order_items->counterpart_product_code = $counterpartProductCode;
                $temp_sales_order_items->counterpart_product_uom = $counterpartProductUom;

                $doInsert = $tempSalesOrderItems->save( $temp_sales_order_items );

                if( $doInsert ) {
                } else {
                    $hasError = true;  
                    $errorProduct[] = "Sales Order Number <strong>{$value['sales_order_number']}</strong>Cannot be Saved. ";
                }

			}

        }
            
        $error['hasError'] = $hasError;
        $error['message'] = $errorProduct;
        
        return $error;
    }

    public function getBranchId ( $code, $company_id ) 
    {
        $branchesTbl = $this->setSource('Branches', $company_id);
        
        $id = $branchesTbl->find()
            ->where([
                'code' => $code,
                'company_id' => $company_id
            ])
            ->first();

         return isset($id) ? $id['id'] : '';   
    }

    public function getAccountId( $code, $company_id )
    {
        $accountsTbl = $this->setSource('Accounts', $company_id);
        
        $id = $accountsTbl->find()
            ->where([
                'code' => $code,
                'company_id' => $company_id
            ])
            ->first();

         return isset($id) ? $id['id'] : '';   
    }

    public function getAllBaof($start_date, $end_date, $company_id, $current_page, $principal_company_id){
        
        $salesOrdersTbl = $this->setSource('sales_orders', $company_id);
        $salesOrderItemsTbl = $this->setSource('sales_order_items', $company_id);
        $multiPrincipalUserSettingTbl = $this->setSource('multi_principal_user_settings', $company_id);
        $invoiceItemsTbl = $this->setSource('invoice_items', $company_id);

        $usernames = $multiPrincipalUserSettingTbl->getAllUsername($company_id, $principal_company_id);
        $usernames = $multiPrincipalUserSettingTbl->getValidatedUser( $company_id, $principal_company_id, $usernames );
        $branch_codes = $this->getValidatedBranches($company_id, $principal_company_id);
        $account_codes = $this->getValidatedAccounts($company_id, $principal_company_id);
        $product_codes = $this->getMappedAndAssignedProducts( $company_id, $principal_company_id );
        
        $where = array(
            'sales_orders.company_id' => $company_id,
            "sales_orders.timestamp BETWEEN '" . $start_date . " 00:00:00' AND '" . $end_date . " 23:59:59'",
            'sales_orders.deleted' => 0,
            'sales_orders.username IN' => $usernames,
            'sales_orders.branch_code IN' => $branch_codes,
            'sales_orders.account_code IN' => $account_codes,
            'sales_order_items.product_code IN' => $product_codes,
        );

        $group = [
            'sales_orders.sales_order_number'
        ];

        $salesOrders = [
            'table' => 'sales_order_items',
            'alias' => 'sales_order_items',
            'type' => 'INNER',
            'conditions' => array(
                'sales_orders.sales_order_number = sales_order_items.sales_order_number'
                )
        ];
        
        $multiPrincipalProductSettings = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'MultiPrincipalPrincipalProductSetting.item_code = sales_order_items.product_code',
                'MultiPrincipalPrincipalProductSetting.status' => 1,
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id
            )
        ];

        $records = $salesOrdersTbl->find()
                    ->group($group)
                    ->where($where)
                    ->join($salesOrders)
                    ->join($multiPrincipalProductSettings)
                    ->offset($current_page)
                    ->limit(1)
                    ->toArray();
        
                                  
		foreach ($records as $data) {
          
			$system_id = $data['sales_order_number'];
			$booking_id = $data['id'];

			$data['SalesOrderItem'] = $this->getSalesOrderItem( $system_id, $company_id, $salesOrderItemsTbl, $product_codes ); 
			$data['BookingApprovalStatus'] = $this->bookingApprovalStatus($system_id, $company_id);
			$data['BookingApprovalEncharge'] = $this->bookingApprovalEncharge($system_id, $company_id);
            
			$data['ActualInvoiceItem'] = array();
			$actualInvoice = $this->invoices($system_id, $company_id, 0);
            
            if( !empty($actualInvoice) ) {
                
                foreach ($actualInvoice as $invoice) {
                    $systemInvoiceNumber = isset($invoice['system_invoice_number']) ? $invoice['system_invoice_number'] : '';
                    $invoiceNumber = isset($invoice['invoice_number']) ? $invoice['invoice_number'] : '';
                        
                    $items = $this->invoicesItems($systemInvoiceNumber, $invoiceNumber, $company_id, 0);
    
                    foreach ($items as $item) {
                        $data['ActualInvoiceItem'][] = $item;
                    }
                }

            }
			
			$data['ActualInvoice'] = isset($actualInvoice) ? $actualInvoice : [];

		}
        
		return $records;
		
	}

    public function invoicesItems( $systemInvoiceNumber, $invoiceNumber, $company_id, $type )
    {
        $invoicesItemsTbl = $this->setSource('invoice_items', $company_id);
        
        $data = $invoicesItemsTbl->find()
                ->where([
                    'company_id' => $company_id,
                    'sales_order_number' => $systemInvoiceNumber,
                    'invoice_number' => $invoiceNumber,
                    'deleted' => $type
                ])
                ->toArray();        

        return isset($data) ? $data : [];
    }

    public function invoices( $system_id, $company_id, $stat )
    {
        $invoicesTbl = $this->setSource('invoices', $company_id);
        
        $data = $invoicesTbl->find()
                ->where([
                    'company_id' => $company_id,
                    'sales_order_number' => $system_id,
                    'deleted' => $stat
                ])
                ->toArray();        

        return isset($data) ? $data : [];
    }

    public function bookingApprovalEncharge( $systemId, $company_id )
    {
        $bookingApprovalEnchargesTbl = $this->setSource('booking_approval_encharges', $company_id);
        
        $data = $bookingApprovalEnchargesTbl->find()
                ->where([
                    'company_id' => $company_id,
                    'booking_system_id' => $systemId
                ])
                ->toArray();        

        return isset($data) ? $data : [];
    }

    public function bookingApprovalStatus( $systemId, $company_id )
    {
        $bookingApprovalStatusTbl = $this->setSource('booking_approval_statuses', $company_id);
        
        $data = $bookingApprovalStatusTbl->find()
                ->where([
                    'company_id' => $company_id,
                    'booking_system_id' => $systemId
                ])
                ->toArray();        

        return isset($data) ? $data : [];
    }

    public function getSalesOrderItem( $system_id, $company_id, $salesOrderItemsTbl, $product_codes )
    {   
        $cond = [
            'sales_order_items.sales_order_number' => $system_id,
            'sales_order_items.product_code IN' => $product_codes,
        ];

        $join = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'MultiPrincipalPrincipalProductSetting.item_code = sales_order_items.product_code', 
                'MultiPrincipalPrincipalProductSetting.status' => 1,
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id
            )
        ];

        $salesOrderItem = $salesOrderItemsTbl->find()
                        ->where($cond) 
                        ->toArray();
                        
        return isset($salesOrderItem) ? $salesOrderItem : [];                
    }

    public function transferTempFex()
    {
        // transfer temporary data to actual table
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
		

        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');


        $fex = $this->getAllFexUploads($start_date, $end_date, $company_id, $index);

        $savedToFex = $this->saveToFex($fex, $company_id, $selected_principal);

        
        echo $savedToFex['transferred'];

    }

    public function saveToFex($records, $company_id, $selected_principal){

        $fieldExFormUploadTbl = $this->setSource('FieldExecutionFormUploads', $selected_principal);
        $fieldExElementsUploadTbl = $this->setSource('FieldExecutionElementUploads', $selected_principal);
        $fieldExStandardUploadTbl = $this->setSource('FieldExecutionStandardUploads', $selected_principal);

        $tempFieldExecutionFormUploadTbl = $this->setSource('TempFieldExecutionFormUploads', $selected_principal);
        $tempFieldExecutionElementUploadTbl = $this->setSource('TempFieldExecutionElementUploads', $selected_principal);
        $tempFieldExecutionStandardUploadTbl = $this->setSource('TempFieldExecutionStandardUploads', $selected_principal);

		$error = array();
		$error['message'] = '';
		$error['count'] = 0;
		$error['transferred'] = 0;
        
		foreach ($records as $key => $data) {

			$fexUpload = $records[0];
			$fexId = $fexUpload['id'];
			$fexUpload['company_id'] = $selected_principal;
			$formCode = $fexUpload['form_code'];
			$branchCode = $fexUpload['branch_code'];

			$fexElements = $fexUpload['FieldExecutionElement'];
			$fexStandards = array();
           
			foreach ($fexElements as $elements) {
				$standards = $elements['FieldExecutionStandard'];
				foreach ($standards as $standard) {
					$fexStandards[] = $standard;
				}
			}
			
			$fexCondition = array(
				'original_id' => $fexId,
				'from_company_id' => $company_id,
				'company_id' => $selected_principal,
				'transferred' => 0
			);

			$doExist = $tempFieldExecutionFormUploadTbl->find()
                        ->where($fexCondition)
                        ->first();
                            
			if($doExist){

                $fieldExFormUpload = $fieldExFormUploadTbl->newEntity();
                
                $form_code = array_key_exists('counterpart_form_code', $doExist) ?  $doExist['counterpart_form_code'] :  $doExist['form_code'];
                $branch_code = array_key_exists('counterpart_branch_code', $doExist) ?  $doExist['counterpart_branch_code'] :  $doExist['branch_code'];
                $formatted_formCode = ['code' => $form_code];
                $form_id = $this->getFormId($formatted_formCode, $selected_principal);

                $fieldExFormUpload->company_id = $fexUpload['company_id'];
                $fieldExFormUpload->user_id = $fexUpload['user_id'];
                $fieldExFormUpload->user_name = $fexUpload['user_name'];
                $fieldExFormUpload->visit_id = $fexUpload['visit_id'];
                $fieldExFormUpload->form_id = $form_id;
                $fieldExFormUpload->form_name = $fexUpload['form_name'];
                $fieldExFormUpload->form_code = $form_code;
                $fieldExFormUpload->branch_code = $branch_code;
                $fieldExFormUpload->branch_name = $fexUpload['branch_name'];
                $fieldExFormUpload->product_group_id = $fexUpload['product_group_id'];
                $fieldExFormUpload->product_group_name = $fexUpload['product_group_name'];
                $fieldExFormUpload->form_perfect_score = $fexUpload['form_perfect_score'];
                $fieldExFormUpload->form_total_score = $fexUpload['form_total_score'];
                $fieldExFormUpload->date_created = $fexUpload['date_created'];
                $fieldExFormUpload->date_inserted = $fexUpload['company_id'];

                $doInsert = $fieldExFormUploadTbl->save($fieldExFormUpload);
                
				if(!$doInsert){ 

					$error['count']++; 
					
					continue;
				}


	        	//		update temp table transferred column and date
				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'company_id' => $selected_principal, 'original_id' => $fexId, 'transferred' => 0);
				if(!$tempFieldExecutionFormUploadTbl->updateAll($updateSet, $updateCondition)) { 
					$error['count']++; 
					continue;
				}
				
		    	//	save all items
                $saveFexElements = $this->saveFexElements($fexElements, $company_id, $selected_principal);
				if(!$saveFexElements){
					$error['count']++; 
					continue;
				}

                $saveFexStandards = $this->saveFexStandards($fexStandards, $company_id, $selected_principal);
				if(!$saveFexStandards){
					$error['count']++; 
					continue;

				}

				// return error count
				$error['transferred']++;

			}			

		}

		return $error;

	}

    function saveFexStandards($fexStandards, $source_company_id, $company_id){

        $TempFieldExecutionStandardUploadTbl = $this->setSource('TempFieldExecutionStandardUploads', $company_id);
        $FieldExecutionV5StandardUploadTbl = $this->setSource('FieldExecutionStandardUploads', $company_id);

		foreach ($fexStandards as $itemKey => $item) {
			
			$_item = $fexStandards[0];

			$itemId = $_item['id'];

			$_item['company_id'] = $company_id;
			
			$doExist = $TempFieldExecutionStandardUploadTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
                    ])
                    ->first();
                    
			if($doExist){

                $FieldExecutionStandardUploads = $FieldExecutionV5StandardUploadTbl->newEntity();
                
                $element_id = array_key_exists('counterpart_element_id', $doExist) ?  $doExist['counterpart_element_id'] :  $doExist['element_id'];
                $standard_id = array_key_exists('counterpart_standard_id', $doExist) ?  $doExist['counterpart_standard_id'] :  $doExist['standard_id'];

                $FieldExecutionStandardUploads->company_id = $_item['company_id'];
                $FieldExecutionStandardUploads->visit_id = $_item['visit_id'];
                $FieldExecutionStandardUploads->element_id = $element_id;
                $FieldExecutionStandardUploads->element_name = $_item['element_name'];
                $FieldExecutionStandardUploads->form_code = $_item['form_code'];
                $FieldExecutionStandardUploads->standard_id = $standard_id;
                $FieldExecutionStandardUploads->standard_name = $_item['standard_name'];
                $FieldExecutionStandardUploads->score = $_item['score'];
                $FieldExecutionStandardUploads->notes = $_item['notes'];
                $FieldExecutionStandardUploads->is_selected = $_item['is_selected'];
                $FieldExecutionStandardUploads->with_image = $_item['with_image'];
                $FieldExecutionStandardUploads->date_inserted = $_item['date_inserted'];

				$doInsert = $FieldExecutionV5StandardUploadTbl->save($FieldExecutionStandardUploads);

				if(!$doInsert){ return false; }

				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $itemId, 'transferred' => 0);
				if(!$TempFieldExecutionStandardUploadTbl->updateAll($updateSet, $updateCondition)) { return false; }

			}else{
				return false;
			}

		}

		return true;
	}

    public function saveFexElements($fexElements, $source_company_id, $company_id){

        $TempFieldExecutionElementUploadTbl = $this->setSource('TempFieldExecutionElementUploads', $company_id);
        $FieldExecutionElementUploadTbl = $this->setSource('FieldExecutionElementUploads', $company_id);
        
		foreach ($fexElements as $itemKey => $item) {
			
			$_item = $fexElements[0];

			$itemId = $_item['id'];

			unset($_item['id']);
			$_item['company_id'] = $company_id;
			
			$doExist = $TempFieldExecutionElementUploadTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
                    ])
                    ->first();
                    
			if($doExist){
                $FieldExecutionElementUploads = $FieldExecutionElementUploadTbl->newEntity();
                
                $form_code = array_key_exists('counterpart_form_code', $doExist) ?  $doExist['counterpart_form_code'] :  $doExist['form_code'];
                $elementId = array_key_exists('counterpart_element_id', $doExist) ?  $doExist['counterpart_element_id'] :  $doExist['element_id'];
                $formatted_formCode = ['code' => $form_code]; 
                $form_id = $this->getFormId($formatted_formCode, $company_id);

                $FieldExecutionElementUploads->company_id = $_item['company_id'];
                $FieldExecutionElementUploads->visit_id = $_item['visit_id'];
                $FieldExecutionElementUploads->form_id = $form_id;
                $FieldExecutionElementUploads->form_name = $_item['form_name'];
                $FieldExecutionElementUploads->form_code = $_item['form_code'];
                $FieldExecutionElementUploads->product_group_id = $_item['product_group_id'];
                $FieldExecutionElementUploads->product_group_name = $_item['product_group_name'];
                $FieldExecutionElementUploads->element_id = $elementId;
                $FieldExecutionElementUploads->element_name = $_item['element_name'];
                $FieldExecutionElementUploads->choice_type = $_item['choice_type'];
                $FieldExecutionElementUploads->description = $_item['description'];
                $FieldExecutionElementUploads->element_perfect_score = $_item['element_perfect_score'];
                $FieldExecutionElementUploads->element_total_score = $_item['element_total_score'];
                $FieldExecutionElementUploads->date_inserted = $_item['date_inserted'];

				$doInsert = $FieldExecutionElementUploadTbl->save($FieldExecutionElementUploads);

				if(!$doInsert){ return false; }

				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $itemId, 'transferred' => 0);
				if(!$TempFieldExecutionElementUploadTbl->updateAll($updateSet, $updateCondition)) { return false; }


			}else{
				return false;
			}

		}

		return true;
	}

    public function countAllFex()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $fieldExecutionFormsUpdloadTbl = $this->setSource("field_execution_form_uploads",$company_id);
        $multi_principal_user_settings_tbl = $this->setSource("multi_principal_user_settings",$company_id);

        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';

        $user_ids = $multi_principal_user_settings_tbl->getAllUserIds($company_id);

        $fieldExUploads = $fieldExecutionFormsUpdloadTbl->find()
                        ->where([
                            'company_id' => $company_id,
                            "date_created BETWEEN '" . $startDate . " 00:00:00' AND '" . $endDate . " 23:59:59'",
                            'user_id IN' => $user_ids
                        ])
                        ->count();

        echo $fieldExUploads;
    }

    public function pushDataFexToTemp()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
		
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $fexUploads = $this->getAllFexUploads($start_date, $end_date, $company_id, $index);
        
        $saveToTemp = $this->saveFexToTemp($fexUploads, $company_id, $selected_principal);
        
        echo json_encode($saveToTemp);
        exit;	
    }

    function saveFexToTemp($fexUploads, $source_company_id, $company_id){

        // NOTE ! company_id is the principal ID

        $connections = ConnectionManager::get('client_' . $company_id);
        $tempFieldExecutionFormUploadTbl = $this->setSource('TempFieldExecutionFormUploads', $company_id);
        $tempFieldExecutionElementUploadTbl = $this->setSource('TempFieldExecutionElementUploads', $company_id);
        $tempFieldExecutionStandardUploadTbl = $this->setSource('TempFieldExecutionStandardUploads', $company_id);
		

		$error = array();
		$errorMessage = array();
		$errorCount = 0;

		$errorBranch = array();
		$errorForm = array();
		$errorElement = array();
		$errorStandard = array();
        
		foreach ( $fexUploads as $key => $uploads ) {
			
			$this->transactional($connections, 'begin');

			$fex = $fexUploads[0];
			
			$fexId = $fex['id'];
			$formCode = $fex['form_code'];
			$branchCode = $fex['branch_code'];
			
			$fexElements = $uploads['FieldExecutionElement'];
			$fexStandards = array();
			foreach ($fexElements as $elements) {
				$standards = $elements['FieldExecutionStandard'];
				foreach ($standards as $standard) {
					$fexStandards[] = $standard;
				}
			}	

			$condition = array(
				'original_id' => $fexId,
				'from_company_id' => $source_company_id,
				'company_id' => $company_id
			);

			$doExist = $tempFieldExecutionFormUploadTbl->find()
                     ->where($condition)
                     ->first();

			$counterpartFormCode = $this->getCounterpartCodes($source_company_id, $company_id, $formCode, 6);
			$counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $company_id, $branchCode, 2);
			        
			// skip this iteration
			if($counterpartFormCode == "" || $counterpartBranchCode == ""){ 
				
				if($counterpartFormCode == ""){ array_push($errorForm, $formCode);}
				
				if($counterpartBranchCode == ""){ array_push($errorBranch, $branchCode); }

				$this->transactional($connections, 'rollback');
				$errorCount++;
				continue; 
			}

            
			//unset the fields so that it can be easily transfer
			foreach ($fexElements as $elements) {
				unset($elements['FieldExecutionStandard']);
			}
			unset($fex['id']);
			unset($fex['FieldExecutionElement']);
			
			
			$fex['company_id'] = $company_id;

			if(!$doExist){
				$fex['form_id'] = $this->getFormId($counterpartFormCode, $company_id); 

                $TempFieldExecutionV5FormUpload = $tempFieldExecutionFormUploadTbl->newEntity();
        
                $TempFieldExecutionV5FormUpload->company_id = $fex['company_id'];
                $TempFieldExecutionV5FormUpload->user_id = $fex['user_id'];
                $TempFieldExecutionV5FormUpload->user_name = $fex['user_name'];
                $TempFieldExecutionV5FormUpload->visit_id = $fex['visit_id'];
                $TempFieldExecutionV5FormUpload->form_id = $fex['form_id'];
                $TempFieldExecutionV5FormUpload->form_name = $fex['form_name'];
                $TempFieldExecutionV5FormUpload->form_code = $fex['form_code'];
                $TempFieldExecutionV5FormUpload->branch_code = $fex['branch_code'];
                $TempFieldExecutionV5FormUpload->branch_name = $fex['branch_name'];
                $TempFieldExecutionV5FormUpload->product_group_id = $fex['product_group_id'];
                $TempFieldExecutionV5FormUpload->product_group_name = $fex['product_group_name'];
                $TempFieldExecutionV5FormUpload->form_perfect_score = $fex['form_perfect_score'];
                $TempFieldExecutionV5FormUpload->form_total_score = $fex['form_total_score'];
                $TempFieldExecutionV5FormUpload->date_created = $fex['date_created'];
                $TempFieldExecutionV5FormUpload->date_inserted = $fex['date_inserted'];

                $TempFieldExecutionV5FormUpload->original_id = $fexId;
                $TempFieldExecutionV5FormUpload->from_company_id = $source_company_id;
                $TempFieldExecutionV5FormUpload->transferred = 0;
                $TempFieldExecutionV5FormUpload->transferred_id = 0;
                $TempFieldExecutionV5FormUpload->counterpart_form_code = $counterpartFormCode['code'];
                $TempFieldExecutionV5FormUpload->counterpart_branch_code = $counterpartBranchCode['code'];

				$doInsert = $tempFieldExecutionFormUploadTbl->save($TempFieldExecutionV5FormUpload);
				
				if(!$doInsert){ 
					$this->transactional($connections, 'rollback');
					$errorCount++;
					continue ;
				}

			}
			$hasProductError = false;
           
			$tempFexElements = $this->saveTempFexElements($fexElements, $source_company_id, $company_id);

			if($tempFexElements['has_error']){
				
				$errorBranch += $tempFexElements['error_branch'];
				$errorForm += $tempFexElements['error_form'];
				$errorCount++;
                $this->transactional($connections, 'rollback');
				continue;
			}

			$tempFexStandards = $this->saveTempFexStandards($fexStandards, $source_company_id, $company_id);

			if($tempFexStandards['has_error']){
				
				$errorElement += $tempFexStandards['error_element'];
				$errorStandard += $tempFexStandards['error_standard'];
				$errorCount++;
                $this->transactional($connections, 'rollback');
				continue;
			}

			// if no error just commit
			$this->transactional($connections, 'commit');

		}

		
		if(count($errorBranch) > 0){
			$err = join(", ", array_unique($errorBranch));
			$error['Branch'] = array_values(array_unique(array_values($errorBranch)));
			array_push($errorMessage, "No code mapping for Branch code : " . $err);
		}

		if(count($errorForm) > 0){
			$err = join(", ", array_unique($errorForm));
			$error['Form'] = array_values(array_unique(array_values($errorForm)));
			array_push($errorMessage, "No code mapping for Form : " . $err);
		}

		if(count($errorElement) > 0){
			$err = join(", ", array_unique($errorElement));
			$error['Form'] = array_values(array_unique(array_values($errorElement)));
			array_push($errorMessage, "No code mapping for Element : " . $err);
		}

		if(count($errorStandard) > 0){
			$err = join(", ", array_unique($errorStandard));
			$error['Form'] = array_values(array_unique(array_values($errorStandard)));
			array_push($errorMessage, "No code mapping for Standard : " . $err);
		}
		if(count($errorMessage) > 0){
			$error['message'] = join("<br>", $errorMessage);
		}		
		$error['count'] = $errorCount;

        if($errorCount == 0) {
            $error['message'] = 'Backup Success.';
            $error['count'] = $errorCount+1;
        }

		return $error;	

	}

    function saveTempFexStandards($fexStandards, $source_company_id, $company_id){

        $tempFieldExStandardsUploadTbl = $this->setSource('TempFieldExecutionStandardUploads', $company_id);

		$errorElement = array();
		$errorStandard = array();
		$hasError = false;
		$message = "";

		foreach ($fexStandards as $itemKey => $item) {
			
			$_item = $fexStandards[0];

			$itemId = $_item['id'];

			unset($_item['id']);
			$_item['company_id'] = $company_id;

			$doExist = $tempFieldExStandardsUploadTbl->find()
                     ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'company_id' => $company_id
                     ])
                     ->first();
			
			$standardId = $_item['standard_id'];
			$elementId = $_item['element_id'];
			

			$counterpartStandardId = $this->getCounterpartCodes($source_company_id, $company_id, $standardId, 8);
			$counterpartElementId = $this->getCounterpartCodes($source_company_id, $company_id, $elementId, 7);
			
			if($standardId == 'null' || is_null($standardId) || $standardId == null) {
				$counterpartStandardId == 'null';
				// array_push($errorAccount, 'sdaddada');
			}

			//skip iteration
			$_err_counter = false;
			if($counterpartStandardId == "" || $counterpartElementId == ""){
				if($standardId == 'null' || is_null($standardId) || $standardId == null) {
					$counterpartStandardId == 'null';
				}else{
					array_push($errorStandard, $standardId);
					$_err_counter = true;
				}
				
				if($counterpartElementId == "") { array_push($errorElement, $elementId); $_err_counter = true;}
				if($counterpartStandardId == "") { array_push($errorStandard, $standardId); $_err_counter = true;}

				if($_err_counter) {
					$hasError = true;
					continue;					
				}

			}

			if(!$doExist){
                
				$tempFieldExecutionStandardsUploads = $tempFieldExStandardsUploadTbl->newEntity();
				
                $tempFieldExecutionStandardsUploads->company_id = $_item['company_id'];
                $tempFieldExecutionStandardsUploads->visit_id = $_item['visit_id'];
                $tempFieldExecutionStandardsUploads->element_id = $_item['element_id'];
                $tempFieldExecutionStandardsUploads->element_name = $_item['element_name'];
                $tempFieldExecutionStandardsUploads->standard_id = $_item['standard_id'];
                $tempFieldExecutionStandardsUploads->standard_name = $_item['standard_name'];
                $tempFieldExecutionStandardsUploads->score = $_item['score'];
                $tempFieldExecutionStandardsUploads->notes = $_item['notes'];
                $tempFieldExecutionStandardsUploads->is_selected = $_item['is_selected'];
                $tempFieldExecutionStandardsUploads->with_image = $_item['with_image'];
                $tempFieldExecutionStandardsUploads->date_inserted = $_item['date_inserted'];

                $tempFieldExecutionStandardsUploads->original_id = $itemId;
                $tempFieldExecutionStandardsUploads->from_company_id = $source_company_id;
                $tempFieldExecutionStandardsUploads->transferred = 0;
                $tempFieldExecutionStandardsUploads->transferred_id = 0;
                $tempFieldExecutionStandardsUploads->counterpart_standard_id = $counterpartStandardId['id'];
                $tempFieldExecutionStandardsUploads->counterpart_element_id = $counterpartElementId['id'];

                $doInsert = $tempFieldExStandardsUploadTbl->save($tempFieldExecutionStandardsUploads);

				if(!$doInsert){ $hasError = true; continue; }

			}

		}

		$error['has_error'] = $hasError;
		$error['error_standard'] = $errorStandard;
		$error['error_element'] = $errorElement;
		
		return $error;
	}

    function saveTempFexElements($fexElements, $source_company_id, $company_id){

        $tempFieldExecutionElementUploadsTbl = $this->setSource('tempFieldExecutionElementUploads', $company_id);

		$errorElement = array();
		$errorForm = array();
		$hasError = false;
		$message = "";
        
		foreach ($fexElements as $itemKey => $item) {
			
			$_item = $fexElements[0];
            
			$itemId = $_item['id'];

			unset($_item['id']);

			$_item['company_id'] = $company_id;


			$doExist = $tempFieldExecutionElementUploadsTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'company_id' => $company_id
                    ])
                    ->first();
			
			$formCode = $_item['form_code'];
			$elementId = $_item['element_id'];
			

			$counterpartFormCode = $this->getCounterpartCodes($source_company_id, $company_id, $formCode, 6);
			$counterpartElementId = $this->getCounterpartCodes($source_company_id, $company_id, $elementId, 7);
			
			if($formCode == 'null' || is_null($formCode) || $formCode == null) {
				$counterpartFormCode == 'null';
			}

			//skip iteration
			$_err_counter = false;
			if($counterpartFormCode == "" || $counterpartElementId == ""){
				if($formCode == 'null' || is_null($formCode) || $formCode == null) {
					$counterpartFormCode == 'null';
				}else{
					array_push($errorForm, $formCode);
					$_err_counter = true;
				}
				
				if($counterpartElementId == "") { array_push($errorElement, $elementId); $_err_counter = true;}
				if($counterpartFormCode == "") { array_push($errorForm, $formCode); $_err_counter = true;}

				if($_err_counter) {
					$hasError = true;
					continue;					
				}

			}

			if(!$doExist){
				$form_id = $this->getFormId($counterpartFormCode, $company_id);
                
                $tempFieldExElementUpload = $tempFieldExecutionElementUploadsTbl->newEntity();
                
                $tempFieldExElementUpload->company_id = $_item['company_id'];
                $tempFieldExElementUpload->visit_id = $_item['visit_id'];
                $tempFieldExElementUpload->form_id = $_item['form_id'];
                $tempFieldExElementUpload->form_name = $_item['form_name'];
                $tempFieldExElementUpload->form_code = $_item['form_code'];
                $tempFieldExElementUpload->product_group_id = $_item['product_group_id'];
                $tempFieldExElementUpload->product_group_name = $_item['product_group_name'];
                $tempFieldExElementUpload->element_id = $_item['element_id'];
                $tempFieldExElementUpload->element_name = $_item['element_name'];
                $tempFieldExElementUpload->choice_type = $_item['choice_type'];
                $tempFieldExElementUpload->description = $_item['description'];
                $tempFieldExElementUpload->element_perfect_score = $_item['element_perfect_score'];
                $tempFieldExElementUpload->element_total_score = $_item['element_total_score'];
                $tempFieldExElementUpload->date_inserted = $_item['date_inserted'];

				$tempFieldExElementUpload->form_id = $form_id;
				$tempFieldExElementUpload->original_id = $itemId;
				$tempFieldExElementUpload->from_company_id = $source_company_id;
				$tempFieldExElementUpload->transferred = 0;
				$tempFieldExElementUpload->transferred_id = 0;
				$tempFieldExElementUpload->counterpart_form_code = $counterpartFormCode['code'];
				$tempFieldExElementUpload->counterpart_element_id = $counterpartElementId['id'];
                
                $doInsert = $tempFieldExecutionElementUploadsTbl->save($tempFieldExElementUpload);
				
				if(!$doInsert){ $hasError = true; continue; }

			}

		}

		$error['has_error'] = $hasError;
		$error['error_form'] = $errorForm;
		$error['error_element'] = $errorElement;
		
		return $error;
	}

    function getFormId($formCode, $company_id){ 
		$fieldExFormTbl = $this->setSource('fieldExecutionForms', $company_id); 
		$form = $fieldExFormTbl->find()
                ->where([
                    'company_id' => $company_id,
                    'deleted_status' => 0,
                    'code' => $formCode['code']
                ])
                ->first();

		return isset($form)? $form['id'] : 0;
	}

    function getAllFexUploads($start_date, $end_date, $company_id, $current_page)
    {
        $multi_principal_user_settings_tbl = $this->setSource("multi_principal_user_settings",$company_id);
        $fieldExecutionFormsUpdloadTbl = $this->setSource("field_execution_form_uploads",$company_id);
        
        $user_ids = $multi_principal_user_settings_tbl->getAllUserIds($company_id);
        
		$record = $fieldExecutionFormsUpdloadTbl->find()
                        ->where([
                            'company_id' => $company_id,
                            "date_created BETWEEN '" . $start_date . " 00:00:00' AND '" . $end_date . " 23:59:59'",
                            'user_id IN' => $user_ids
                        ])
                        ->offset($current_page)
                        ->limit(1)
                        ->toArray();
                        
		foreach ($record as $fex) {

			$visit_id = $fex['visit_id'];
			$form_id = $fex['form_id'];

			$fex['FieldExecutionElement'] = $this->getAllFexElementsUploads($form_id, $visit_id, $company_id);
            
		}

		return $record;
		
	}

    function getAllFexElementsUploads($form_id, $visit_id, $company_id){

        $fieldExecutionElementUploadsTbl = $this->setSource('field_execution_element_uploads', $company_id);

		$where = array(
			'company_id' => $company_id,
			'form_id' => $form_id,
			'visit_id' => $visit_id
		);
        
        $record = $fieldExecutionElementUploadsTbl->find()
                ->where($where)
                ->toArray();
                
		foreach ($record as $fex) {

			$visit_id = $fex['visit_id'];
			$element_id = $fex['element_id'];

			$fex['FieldExecutionStandard'] = $this->getAllFexStandardUploads($element_id, $visit_id, $company_id);

		}
        
		return $record;
		
	}

    function getAllFexStandardUploads($element_id, $visit_id, $company_id){

        $fieldExecutionStandardsUploadsTbl = $this->setSource('field_execution_standard_uploads', $company_id);

		$where = array(
			'company_id' => $company_id,
			'element_id' => $element_id,
			'visit_id' => $visit_id
		);
        
		$record = $fieldExecutionStandardsUploadsTbl->find()
                ->where($where)
                ->toArray();
                
		return $record;
		
	}

    public function countAllVisits()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $noOrderInputTable = $this->setSource("no_order_inputs",$company_id);
        $multi_principal_user_settings_tbl = $this->setSource("multi_principal_user_settings",$company_id);

        $result = [];
        $result['message'] = [];
        $result['total'] = 0;

        $principal_company_id = $_POST['principal'];
		$startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');

        $usernames = $multi_principal_user_settings_tbl->getAllUsername($company_id); 
        $usernames = $multi_principal_user_settings_tbl->getValidatedUser( $company_id, $principal_company_id, $usernames );
        
        $branch_codes = $this->getValidatedBranches($company_id, $principal_company_id);
        $account_codes = $this->getValidatedAccounts($company_id, $principal_company_id);
        $no_order_inputs = $this->getValidatedNoOrderInputs( $company_id, $principal_company_id );
        
        if( count($usernames) == 0 ) {
            $result['message'][] = "No Username Found in Mapping";
        }

        if( count($branch_codes) == 0 ) {
            $result['message'][] = "No Branch Code Found in Mapping";
        }
        
        if( count($account_codes) == 0 ) {
            $result['message'][] = "No Account Code Found in Mapping";
        }
        
        if( count($no_order_inputs) == 0 ) {
            $result['message'][] = "No Visit Status Found in Mapping";
        }

        if( count($result['message']) == 0 ) {
            $record = $noOrderInputTable->find() 
                    ->where([
                        'company_id' => $company_id,
                        "timestamp BETWEEN '" . $startDate . " 00:00:00' AND '" . $endDate . " 23:59:59'",
                        "username IN" => $usernames,
                        "branch_code IN" => $branch_codes,
                        "account_code IN" => $account_codes,
                        "no_order_code IN" => $no_order_inputs,
                    ])
                    ->count();

            $result['total'] = $record;
            $result['message'][] = "Please Check Code Mapping.";
        }

        echo json_encode($result); 
        exit;
    }

    function transferTempVisit(){

		// transfer temporary data to actual table
		$this->autoRender = false;
		$user = $this->Auth->user();
        $company_id = $user['company_id'];
		
		
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $visits = $this->getAllVisitStatusLogs($start_date, $end_date, $company_id, $index, $selected_principal);
        $savedToVaof = $this->saveToVisit($visits, $company_id, $selected_principal);
        
        echo json_encode($savedToVaof);

	}

    public function saveToVisit($visits, $company_id, $selected_principal)
    {			
        $connection = ConnectionManager::get('client_' . $selected_principal);
        
        $NoOrderInputTable = $this->setSource('NoOrderInputs', $selected_principal);
        $TbSessionsTable = $this->setSource('TbSessions', $selected_principal);
        $VisitImagesTable = $this->setSource('VisitImages', $selected_principal);
        $VisitSignaturesTable = $this->setSource('VisitSignatures', $selected_principal);    


        $tempNoOrderInputTable = $this->setSource('TempNoOrderInputs', $selected_principal);

        
		$result = array();
		$result['message'] = [];
		$result['count'] = 0;
		$result['transferred'] = 0;
        $date = date('Y-m-d H:i:s');

		$error = array();
		$error['message'] = '';
		$error['count'] = 0;
		$error['transferred'] = 0;
        
		foreach ($visits as $key => $logs) {

            $data = json_decode( json_encode($visits[$key]), true );

			$original_id = $logs['id'];
			$log['company_id'] = $selected_principal;
			$accountCode = $logs['account_code'];
			$branchCode = $logs['branch_code'];

            
			$visitSignatures = $logs['VisitSignatures']; 
			$visitImages = $logs['VisitImages'];
			$tbsessions = $logs['Tbsessions'];
			
			unset($data['id']);
			unset($data['VisitSignatures']);
			unset($data['VisitImages']);
			unset($data['Tbsessions']);
			
			$doExist = $tempNoOrderInputTable->find()
                    ->where([
                        'company_id' => $selected_principal,
                        'from_company_id' => $company_id,
                        'original_id' => $original_id,
                        'transferred' => 0
                    ])
                    ->first();
                              
            if($doExist){
                
                $noOrderInput = $NoOrderInputTable->newEntity();
                
                foreach( $data as $key => $value ) {
                    switch( $key ) {
                        case 'username':
                            $noOrderInput->username = $doExist['counterpart_username'];
                        break;   
                        case 'company_id':
                            $noOrderInput->company_id = $selected_principal;
                        break; 
                        case 'account_code':
                            $noOrderInput->account_code = $doExist['counterpart_account_code'];
                        break; 
                        case 'branch_code':
                            $noOrderInput->branch_code = $doExist['counterpart_branch_code'];
                        break;  
                        case 'no_order_code':
                            $noOrderInput->no_order_code = $doExist['counterpart_no_order_code'];
                        break;  
                        default:
                            $noOrderInput->$key = $value;
                    }
                }
                
                $doInsert = $NoOrderInputTable->save($noOrderInput);
                  
                if(!$doInsert){
                    $result['message'][] = "Unable To Save No Order Inputs";
                    $error['count']++; 
                    continue;
                }

                $transferred_id = $doInsert['id'];
                $updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
                $updateCondition = array('from_company_id' => $company_id, 'company_id' => $selected_principal, 'original_id' => $original_id, 'transferred' => 0); //, 'transferred' => 0

                if(!$tempNoOrderInputTable->updateAll($updateSet, $updateCondition)) {
                    $error['count']++; 
                    continue;
                    }

                // save all items

                if( count($tbsessions) > 0 ) {
                    $ifSaveTbSessions = $this->saveTbSessions($tbsessions, $company_id, $selected_principal);
                    if($ifSaveTbSessions['hasError']){
                        foreach( $ifSaveTbSessions['message'] as $key => $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                        continue;
                    }
                }
                if( count($visitImages) > 0 ) {
                    $ifSaveVisitImages = $this->saveVisitImages($visitImages, $company_id, $selected_principal);
                    if($ifSaveVisitImages['hasError']){
                        foreach( $ifSaveVisitImages['message'] as $key => $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                        continue;
                    }
                }
                

                if( count($visitSignatures) > 0 ) {
                    $ifSavedVisitSignature = $this->saveVisitSignature($visitSignatures, $company_id, $selected_principal);
                    if($ifSavedVisitSignature['hasError']){
                        foreach( $ifSavedVisitSignature['message'] as $key => $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                        continue;
                    }
                }



                // return error count
                $error['transferred']++;

            }
		}

        $result['transferred'] = $error['transferred'];
		return $result;
    }

    public function saveVisitSignature ($visitSignatures, $source_company_id, $company_id)
    {
        $tempVisitSignaturesTbl = $this->setSource('TempVisitSignatures', $company_id);
        $visitSignaturesTbl = $this->setSource('VisitSignatures', $company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;

        foreach ($visitSignatures as $itemKey => $item) {
			
			$_item = $visitSignatures[0];

			$itemId = $_item['id'];
            
			$_item['company_id'] = $company_id;
			
			$doExist = $tempVisitSignaturesTbl->find()
                     ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
                     ])
                     ->first();

			if($doExist){

                $visitSignatures = $visitSignaturesTbl->newEntity();

                $user_id = $doExist['counterpart_user_id'];

                $visitSignatures->user_id = $user_id;
                $visitSignatures->visit_number = $_item['visit_number'];
                $visitSignatures->image = $_item['image'];
                $visitSignatures->company_id = $_item['company_id'];
                $visitSignatures->created = $_item['created'];
                $visitSignatures->modified = $_item['modified'];

				$doInsert = $visitSignaturesTbl->save($visitSignatures);

				if(!$doInsert){
                    $result['message'][] = "Unable To Transfer Visit Signatures.";
                    $result['hasError'] = true;
                    continue;
                }

				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $itemId, 'transferred' => 0);

				if(!$tempVisitSignaturesTbl->updateAll($updateSet, $updateCondition)) {
                    $result['message'][] = "Unable To Update Temp Visit Signatures.";
                    $result['hasError'] = true;
                    continue;
                }


			}else {
                $result['message'][] = "Temp Visit Signatures already transferred or not exists.";
                $result['hasError'] = true;
                continue;
            }

		}

		return $result;
    }

    public function saveVisitImages($visitImages, $source_company_id, $company_id)
    {
        $tempVisitImagesTbl = $this->setSource('TempVisitImages', $company_id);
        $visitImagesTbl = $this->setSource('VisitImages', $company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;


        foreach ($visitImages as $itemKey => $item) {
			
			$_item = $visitImages[0];

			$itemId = $_item['id'];

			unset($_item['id']);
			$_item['company_id'] = $company_id;
            
			$doExist = $tempVisitImagesTbl->find()
                    ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
                    ])
                    ->first();

			if($doExist){

                $visitImages = $visitImagesTbl->newEntity();

                $user_id = $doExist['counterpart_user_id'];

                $visitImages->user_id = $user_id;
                $visitImages->visit_number = $_item['visit_number'];
                $visitImages->image = $_item['image'];
                $visitImages->type = $_item['type'];
                $visitImages->company_id = $_item['company_id'];
                $visitImages->username = $_item['username'];
                $visitImages->notes = $_item['notes'];
                $visitImages->created = $_item['created'];
                $visitImages->modified = $_item['modified'];
                
                $doInsert = $visitImagesTbl->save($visitImages);
				

				if(!$doInsert){
                    $result['message'][] = "Unable To Transfer Temp Visit Images";
                    $result['hasError'] = true;
                    continue;
                }

				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $itemId, 'transferred' => 0);

				if(!$tempVisitImagesTbl->updateAll($updateSet, $updateCondition)) {
                    $result['message'][] = "Unable To Transfer Temp Visit Images";
                    $result['hasError'] = true;
                    continue;
                }


			}else { 
                
                $result['message'][] = "Data is not exists or Already transferred.";
                $result['hasError'] = true;
                continue;
            }

		}

		return $result;
    }

    public function saveTbSessions($tbsessionsData, $source_company_id, $company_id)
    {

        $tempTbSessionTbl = $this->setSource('TempTbsessions', $company_id);
        $tbSessionTbl = $this->setSource('Tbsessions', $company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        
        foreach ($tbsessionsData as $itemKey => $item) {
            
			$itemId = $item['id'];

			$doExist = $tempTbSessionTbl->find()
                     ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
                     ])
                     ->first();      
        
            $test = [
                'from_company_id' => $source_company_id,
                        'original_id' => $itemId,
                        'transferred' => 0
            ];

			if($doExist){

                $tbSessions = $tbSessionTbl->newEntity();
  
				if($item['username']) {
                    $username = $doExist['counterpart_username'];
                    $tbSessions->username = $username;
                }

                $tbSessions->visit_id = $item['visit_id'];
                $tbSessions->sessionnumber = $item['sessionnumber'];
                $tbSessions->audience_group_id = $item['audience_group_id'];
                $tbSessions->audience_id = $item['audience_id'];
                $tbSessions->firstname = $item['firstname'];
                $tbSessions->lastname = $item['lastname'];
                $tbSessions->session_id_number = $item['session_id_number'];
                $tbSessions->content_type = $item['content_type'];
                $tbSessions->content_id = $item['content_id'];
                $tbSessions->action = $item['action'];
                $tbSessions->company_id = $company_id;
                $tbSessions->longitude = $item['longitude'];
                $tbSessions->latitude = $item['latitude'];
            
                $doInsert = $tbSessionTbl->save($tbSessions);
                
				if(!$doInsert){
                    $result['message'][] = "Unable to Transfer Temp TB Sessions.";
                    $result['hasError'] = true;
                    continue;
                }

				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => "'" . date('Y-m-d H:i:s') . "'", 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $source_company_id, 'company_id' => $company_id, 'original_id' => $itemId, 'transferred' => 0);
                
				if(!$tempTbSessionTbl->updateAll($updateSet, $updateCondition)) {
                    $result['message'][] = "Unable to Update Temp TB Sessions.";
                    $result['hasError'] = true;
                    continue;
                }


			}else{
                $result['message'][] = "Temp TB Sessions has no Data or Already Transfered.";
                $result['hasError'] = true;
                continue;
			}

		}

		return $result;
    }

    public function pushDataVisitToTemp()
    {
        $this->autoRender = false;
		$user = $this->Auth->user();
        $company_id = $user['company_id'];

        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $InventoryCountInformationTbl = $this->setSource('inventory_count_ems_informations', $company_id);
        $InventoryCountInformationTbl = $this->setSource('inventory_count_ems_items', $company_id);

        $TempInventoryCountInformation = $this->setSource('temp_inventory_count_informations', $selected_principal);
        $TempInventoryCountItem = $this->setSource('temp_inventory_count_items', $selected_principal);

        $visits = $this->getAllVisitStatusLogs($start_date, $end_date, $company_id, $index, $selected_principal);
        $saveToTemp = $this->saveVisitToTemp($visits, $company_id, $selected_principal);

        echo json_encode($saveToTemp);	
        exit;   
    }

    function saveVisitToTemp($visits, $source_company_id, $principal_company_id ){

        $tempNoOrderInputTable = $this->setSource('tempNoOrderInputs', $principal_company_id );
        $tempTbSessionsTable = $this->setSource('TempTbsessions', $principal_company_id );
        $tempVisitImagesTable = $this->setSource('TempVisitImages', $principal_company_id );
        $tempVisitSignaturesTable = $this->setSource('TempVisitSignatures', $principal_company_id );
    
		$result = [];
        $result['message'] = [];
        $result['errorCount'] = 0;
        $errorCount = 0;
        $date = date('Y-m-d H:i:s');
        
		foreach ($visits as $key => $logs) {
            
			$log = $logs;
			$original_id = $logs['id'];
			$accountCode = $logs['account_code'];
			$branchCode = $logs['branch_code'];
			$noOrderCode = $logs['no_order_code'];
			$username = $logs['username'];
			
			$visitSignatures = $logs['VisitSignatures'];
			$visitImages = $logs['VisitImages'];
			$tbsessions = $logs['Tbsessions'];
            
            $cond = [
                'original_id' => $original_id,
                'from_company_id' => $source_company_id,
                'company_id' => $principal_company_id
            ];
			$doExist = $tempNoOrderInputTable->find()->where( $cond )->first();
                               
			$counterpartAccountCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $accountCode, 1);
			$counterpartBranchCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $branchCode, 2);
			$counterpartNoOrderCode = $this->getCounterpartCodes($source_company_id, $principal_company_id, $noOrderCode, 5);
            $counterpartUsername = $this->getCounterpartCodes($source_company_id, $principal_company_id, $username, 10);
           
            $counterpartUserId = $this->getCounterpartUserId( $counterpartUsername['code'] )['id'];
         
			if(!$doExist){

                $tempnoOrderInput = $tempNoOrderInputTable->newEntity();
                                
                $tempnoOrderInput->username = $log['username'];
                $tempnoOrderInput->company_id = $principal_company_id;
                $tempnoOrderInput->visit_number = $log['visit_id'];
                $tempnoOrderInput->no_order_code = $log['no_order_code'];
                $tempnoOrderInput->account_code = $log['account_code'];
                $tempnoOrderInput->branch_code = $log['branch_code'];
                $tempnoOrderInput->timestamp = $log['timestamp'];
                $tempnoOrderInput->created = $log['created'];
                $tempnoOrderInput->modified = $log['modified'];
               
                $tempnoOrderInput->original_id = $original_id;
                $tempnoOrderInput->from_company_id = $source_company_id;
                $tempnoOrderInput->transferred = 0;
                $tempnoOrderInput->transferred_id = 0;
                $tempnoOrderInput->counterpart_account_code = $counterpartAccountCode['code'];
                $tempnoOrderInput->counterpart_branch_code = $counterpartBranchCode['code'];
                $tempnoOrderInput->counterpart_no_order_code = $counterpartNoOrderCode['code'];+
                $tempnoOrderInput->counterpart_user_id = $counterpartUserId;
                $tempnoOrderInput->counterpart_username = $counterpartUsername['code'];
                
                $doInsert = $tempNoOrderInputTable->save($tempnoOrderInput);
                
				if(!$doInsert){ 
                    $result['message'][] = "Unable to Save Temp No Order Inputs";
					$errorCount++;
					continue ;
				}

			}
            

            if( count( $tbsessions ) > 0 ) {
                $tempTbsession = $this->saveTempTbSessions($tbsessions, $source_company_id, $principal_company_id);
                if($tempTbsession['hasError']){
                    foreach( $tempTbsession['message'] as $key => $value ) {
                        $result['message'][] = $value;
                        $errorCount++;
                    }
                }
            }


            if( count( $visitSignatures ) > 0 ) {
                $tempVisitSignature = $this->saveTempVisitSignature($visitSignatures, $source_company_id, $principal_company_id);

                if($tempVisitSignature['hasError']){
                    foreach( $tempVisitSignature['message'] as $key => $value ) {
                        $result['message'][] = $value;
                        $errorCount++;
                    }
                }
			}

            if( count($visitImages) > 0 ) {
                $tempVisitImage = $this->saveTempVisitImages($visitImages, $source_company_id, $principal_company_id);
    
                if($tempVisitImage['hasError']){
                    foreach( $tempVisitImage['message'] as $key => $value ) {
                        $result['message'][] = $value;
                        $errorCount++;
                    }
                }
            }

		}

        $result['errorCount'] = $errorCount;
		return $result;
	}

    public function saveTempVisitImages($visitImages, $source_company_id, $company_id)
    {
        $tempVisitImageTbl = $this->setSource('tempVisitImages', $company_id);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        
		foreach ($visitImages as $itemKey => $item) {
			
			$original_id = $item['id'];

			$doExist = $tempVisitImageTbl->find()
                     ->where ([
                        'from_company_id' => $source_company_id,
                        'original_id' => $original_id,
                        'company_id' => $company_id
                     ])
                     ->first();

			$counterpartUserId = 0;
			$counterpartUsername = '';

			$counterpartUsers = $this->getCounterpartCodes($source_company_id, $company_id, $item['username'], 10);

			if ($counterpartUsers) {
                $result['message'][] = "No Counterpart User for Username {$item['username']}";
                $result['hasError'] = true;
                continue;
			}
			
			if(!$doExist){

                $tempVisitImage = $tempVisitImageTbl->newEntity();

                $tempVisitImage->id = $item['id'];
                $tempVisitImage->visit_number = $item['visit_number'];
                $tempVisitImage->image = $item['image'];
                $tempVisitImage->type = $item['type'];
                $tempVisitImage->company_id = $item['company_id'];
                $tempVisitImage->username = $item['username'];
                $tempVisitImage->notes = $item['notes'];
                $tempVisitImage->created = $item['created'];
                $tempVisitImage->modified = $item['modified'];

                $tempVisitImage->original_id = $original_id;
                $tempVisitImage->company_id = $company_id;
                $tempVisitImage->from_company_id = $source_company_id;
                $tempVisitImage->transferred = 0;
                $tempVisitImage->transferred_id = 0;
                $tempVisitImage->counterpart_user_id = $counterpartUserId;

                $doInsert = $tempVisitImageTbl->save($tempVisitImage);
                				
				if(!$doInsert){ 
                    $result['message'][] = "Temp Visit Image Cannot be saved.";
                    $result['hasError'] = true;
                    continue;
                }

			}

		}

		return $result;
    }

    public function saveTempVisitSignature($visitSignatures, $source_company_id, $company_id)
    {
        $tempVisitSignatureTbl = $this->setSource('TempVisitSignatures', $company_id);
        
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        
		foreach ($visitSignatures as $itemKey => $item) {
			
			$original_id = $item['id'];

			$doExist = $tempVisitSignatureTbl->find()
                    ->where([
                    'from_company_id' => $source_company_id,
                    'original_id' => $original_id,
                    'company_id' => $company_id
                    ])
                    ->first();

            $username = $this->getUsernameByUserId( $item['user_id'] );
			$counterpartUsers = $this->getCounterpartCodes($source_company_id, $company_id, $username, 10 );

			if ( empty($counterpartUsers) ) {
                $result['message'][] = "No Counterpart Username in Username {$username}";
                $result['hasError'] = true;
                continue;
			}

            $counterpart_user_id = $this->getUserIdByUsername( $counterpartUsers );
			
			if( !$doExist ){

				$tempVisitSignature = $tempVisitSignatureTbl->newEntity();
				
                $tempVisitSignature->visit_number = $item['visit_id'];
                $tempVisitSignature->image = $item['image'];
                $tempVisitSignature->company_id = $company_id;
                $tempVisitSignature->user_id = $item['user_id'];
                $tempVisitSignature->created = $item['created'];
                $tempVisitSignature->modified = $item['modified'];

                $tempVisitSignature->original_id = $original_id;
                $tempVisitSignature->from_company_id = $source_company_id;
                $tempVisitSignature->transferred = 0;
                $tempVisitSignature->transferred_id = 0;
                $tempVisitSignature->counterpart_user_id = $counterpart_user_id;

                $doInsert = $tempVisitSignatureTbl->save($tempVisitSignature);    

				if(!$doInsert){ 
                    $result['message'][] = "Temp Visit Cannot Be Saved Signatures.";
                    $result['hasError'] = true;
                    continue;
                }

			}

		}

		return $result;
    }

    public function saveTempTbSessions( $tbsessions, $source_company_id, $company_id )
    {
        $tempTbSessionsTbl = $this->setSource('TempTbsessions', $company_id);
        
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;

		foreach ($tbsessions as $itemKey => $item) {
            
			$original_id = $item['id'];

			$doExist = $tempTbSessionsTbl->find()
                      ->where([
                        'from_company_id' => $source_company_id,
                        'original_id' => $original_id,
                        'company_id' => $company_id
                      ])
                     ->first();
            
            $counterpart_username = $this->getCounterpartCodes( $source_company_id, $company_id, $item['username'], 10);

            if( empty($counterpart_username) ) {
                
                $result['message'][] = "No Counterpart Username for Username{$item['username']} TbSessions";
                $result['hasError'] = true;
                continue;
            }
            
			if(!$doExist){

                $tempTbSessions = $tempTbSessionsTbl->newEntity();

                $tempTbSessions->visit_id = $item['visit_id'];
                $tempTbSessions->sessionnumber = $item['sessionnumber'];
                $tempTbSessions->audience_group_id = $item['audience_group_id'];
                $tempTbSessions->audience_id = $item['audience_id'];
                $tempTbSessions->firstname = $item['firstname'];
                $tempTbSessions->lastname = $item['lastname'];
                $tempTbSessions->session_id_number = $item['session_id_number'];
                $tempTbSessions->content_type = $item['content_type'];
                $tempTbSessions->content_id = $item['content_id'];
                $tempTbSessions->action = $item['action'];
                $tempTbSessions->timestamp = $item['timestamp'];
                $tempTbSessions->username = $item['username'];
                $tempTbSessions->longitude = $item['longitude'];
                $tempTbSessions->latitude = $item['latitude'];
				
                $tempTbSessions->original_id = $original_id;
                $tempTbSessions->company_id = $company_id;
                $tempTbSessions->from_company_id = $source_company_id;
                $tempTbSessions->transferred = 0;
                $tempTbSessions->transferred_id = 0;
                $tempTbSessions->counterpart_username = $counterpart_username['counterpart_code'];
                
                $doInsert = $tempTbSessionsTbl->save($tempTbSessions);

				if(!$doInsert){
                    $result['message'][] = "Temp TB Sessions cannot be saved";
                    $result['hasError'] = true;
                    continue;
                }

			}

		}

		return $result;
    }

    public function getCounterpartUsers($source_company_id, $company_id, $username) {
		$counterpartUserId = 0;
		$counterpartUsername = '';

        $usersTbl = TableRegistry::get('users');
        
		$users = $usersTbl->find()
                  ->where([
                      'company_id' => $source_company_id,
                      'username' => $username 
                  ])
                  ->first();
                            
		if ($users) {

			$userid = $users['id'];
			$counterpartUserId = $this->getCounterpartCodes($source_company_id, $company_id, $userid, 10);
            
			if ($counterpartUserId != '') {

                $temp_account = $usersTbl->find()
                  ->where([
                      'company_id' => $company_id,
                      'id' => $counterpartUserId['id'] 
                  ])
                  ->first(); 

				if ($temp_account) {
					$counterpartUsername = $temp_account['username'];
				} else {
					return false;
				}

			} else {
				return false;
			}

		} else {
			return false;
		}

		return array('id' => $counterpartUserId, 'username' => $counterpartUsername);

	}

    public function getAllVisitStatusLogs($start_date, $end_date, $company_id, $current_page, $principal)
    {
        $multi_principal_user_settings_tbl = $this->setSource('multi_principal_user_settings', $company_id);
        $noOrderInputTable = $this->setSource('no_order_inputs', $company_id);

        $usernames = $multi_principal_user_settings_tbl->getAllUsername($company_id);
        $usernames = $multi_principal_user_settings_tbl->getValidatedUser( $company_id, $principal, $usernames );
        $branch_codes = $this->getValidatedBranches($company_id, $principal);
        $account_codes = $this->getValidatedAccounts($company_id, $principal);
        $no_order_inputs = $this->getValidatedNoOrderInputs( $company_id, $principal );
      
        $record = $noOrderInputTable->find() 
                ->where([
                    'company_id' => $company_id,
                    "timestamp BETWEEN '" . $start_date . " 00:00:00' AND '" . $end_date . " 23:59:59'",
                    "username IN" => $usernames,
                    "account_code IN" => $branch_codes,
                    "branch_code IN" => $account_codes,
                    "no_order_code IN" => $no_order_inputs,

                ])
                ->offset($current_page)
                ->limit(1)
                ->toArray();


		foreach ($record as $order) {

			$visit_id = $order['visit_id']; 

			$order['Tbsessions'] = $this->getAllTbsessions($visit_id, $company_id); 
			$order['VisitImages'] = $this->getAllVisitImages($visit_id, $company_id); 
			$order['VisitSignatures'] = $this->getAllVisitSignatures($visit_id, $company_id);

		}
        
		return $record;
    }

    function getAllVisitSignatures($visit_id, $company_id){
		$visitSignatursTbl = $this->setSource("visit_signatures", $company_id);
		$query['conditions'] = array(
			'visit_number' => $visit_id,
			'company_id' => $company_id
			);
		$record = $visitSignatursTbl->find('all', $query)->toArray();

		return $record;
	}

    function getAllVisitImages($visit_id, $company_id){
		$visitImageTbl = $this->setSource("visit_images", $company_id);
		$query['conditions'] = array(
			'visit_number' => $visit_id,
			'company_id' => $company_id,
			'type' => 1
			);
		$record = $visitImageTbl->find('all', $query)->toArray();

		return $record;
	}

    public function getAllTbsessions( $visit_id, $company_id )
    {
        $tbSessionTbl = $this->setSource('tbsessions', $company_id);

        $record = $tbSessionTbl->find()
                      ->where([
                          'visit_id' => $visit_id,
                          'company_id' => $company_id
                          ])
                      ->toArray();

		return $record;
    }

    public function countAllInventoryCount( )
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $multiPrincipalUserSettingsTbl= $this->setSource("multi_principal_user_settings",$company_id);
        $inventoryCountEmsInformationsTbl= $this->setSource("inventory_count_ems_informations",$company_id);

        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';

        $usernames = $multiPrincipalUserSettingsTbl->getAllUsername( $company_id );

        $where = [
            'inventory_count_ems_informations.company_id' => $company_id,
            "inventory_count_ems_informations.created BETWEEN '" . $startDate . " 00:00:00' AND '" . $endDate . " 23:59:59'",
            'inventory_count_ems_informations.deleted' => 0,
            'inventory_count_ems_informations.username IN' => $usernames
        ];

        $joinICItems = [
            'table' => 'inventory_count_ems_items',
            'alias' => 'InventoryCountEmsItem',
            'type' => 'INNER',
            'conditions' => array(
                'inventory_count_ems_informations.unique_id = InventoryCountEmsItem.inventory_count_info_unique_id',
                'InventoryCountEmsItem.company_id' => $company_id
            )
        ];

        $joinMultiPProductSettings = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
                'MultiPrincipalPrincipalProductSetting.item_code = InventoryCountEmsItem.product_code',
                'MultiPrincipalPrincipalProductSetting.status' => 1
            )
        ];


        $inventoryCountEmsInformations = $inventoryCountEmsInformationsTbl->find()
                                    ->where($where)
                                    ->join($joinICItems)
                                    ->join($joinMultiPProductSettings)
                                    ->count();
                                    
        echo json_encode ($inventoryCountEmsInformations);
        exit();
    }

    public function pushDataICToTemp()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : '';
        $index = isset($_POST['index']) ? $_POST['index'] : '';


        $inventoryCountInformations = $this->getAllInventoryCount( $start_date, $end_date, $company_id, $index );
        
        $saveToTemp = $this->saveToTemp( $inventoryCountInformations, $company_id, $selected_principal );

        echo json_encode($saveToTemp);
        exit;
    }

    public function getAllInventoryCount( $start_date, $end_date, $company_id, $current_page )
    {
        $connection = ConnectionManager::get('client_' . $company_id); 
        $inventoryCountInformationsTbl = TableRegistry::get('inventory_count_ems_informations', ['connection' => $connection]); 
        $inventoryCountItemsTbl = TableRegistry::get('inventory_count_ems_items', ['connection' => $connection]);
        
        $multiPrincipalUserSettingsTbl= $this->setSource("multi_principal_user_settings",$company_id);
        $usernames = $multiPrincipalUserSettingsTbl->getAllUsername( $company_id );
        


        $where = [
            'inventory_count_ems_informations.company_id' => $company_id,
            "inventory_count_ems_informations.created BETWEEN '" . $start_date . " 00:00:00' AND '" . $end_date . " 23:59:59'",
            'inventory_count_ems_informations.deleted' => 0,
            'inventory_count_ems_informations.username IN' => $usernames
        ];

        $joinICItems = [
            'table' => 'inventory_count_ems_items',
            'alias' => 'inventory_count_ems_items',
            'type' => 'INNER',
            'conditions' => array(
                'inventory_count_ems_informations.unique_id = inventory_count_ems_items.inventory_count_info_unique_id',
                'inventory_count_ems_items.company_id' => $company_id
            )
        ];

        $joinMultiPProductSettings = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
                'MultiPrincipalPrincipalProductSetting.item_code = inventory_count_ems_items.product_code',
                'MultiPrincipalPrincipalProductSetting.status' => 1
            )
        ];

        $inventoryCountInformations = $inventoryCountInformationsTbl->find()
                                   ->where($where)
                                   ->join($joinICItems)
                                   ->join($joinMultiPProductSettings)
                                   ->limit(1)
                                   ->offset($current_page);
        
        $to_return = $this->inventoryCountItems( $inventoryCountInformations, $company_id, $inventoryCountItemsTbl );
                                   
       return $to_return;
    }

    public function saveToTemp( $inventoryCountInformations, $company_id, $selected_principal_company_id )
    {
        $this->autoRender = false;
        $connection = ConnectionManager::get('client_' . $company_id);
        $inventoryCountInformationsTbl = TableRegistry::get('inventory_count_ems_informations', ['connection' => $connection]);
        $inventoryCountItemsTbl = TableRegistry::get('inventory_count_ems_items', ['connection' => $connection]);
        $tempInventoryCountInformationsTbl = TableRegistry::get('temp_inventory_count_informations', ['connection' => $connection]);
        $tempInventoryCountItemsTbl = TableRegistry::get('temp_inventory_count_items', ['connection' => $connection]);

        $error = array();
		$errorMessage = array();
		$errorCount = 0;
		$errorAccount = array();
		$errorBranch = array();
		$errorWarehouse = array();
        $errorProduct = array();
        
        $newInventoryCountInformations = $inventoryCountInformations['InventoryCountInformation'];
		foreach ( $newInventoryCountInformations as $key => $information ) {

			$informationCondition = array(
				'original_id' => $information['id'],
				'unique_id' => $information['unique_id'],
				'from_company_id' => $selected_principal_company_id,
				'company_id' => $company_id,
            );
            
            $doExists = $this->doExists( $informationCondition, $tempInventoryCountInformationsTbl );
            
			$items = $inventoryCountInformations['InventoryCountItems']; 
			$informationId = $information['id'];
			$accountCode = isset($information['account_code']) ? $information['account_code'] : '';
			$branchCode = isset($information['branch_code']) ? $information['branch_code'] : '';
           
            
            $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal_company_id, $accountCode, 1);
			$counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal_company_id, $branchCode, 2);
            
			if($counterpartAccountCode == "" || $counterpartBranchCode == ""){ 
				
				if($counterpartAccountCode == ""){ 
					array_push($errorAccount, $accountCode);
					array_push($errorMessage, "No code mapping for Account code : " . $accountCode);
				}
				
				if($counterpartBranchCode == ""){ 
					array_push($errorBranch, $branchCode);
					array_push($errorMessage, "No code mapping for Branch code : " . $branchCode); 
				}

				$errorCount++;
			    continue; 
			}
			
            $information['company_id'] = $company_id;
            
			if(!$doExists){

				$TempInventoryCountInformation = $tempInventoryCountInformationsTbl->newEntity();
				foreach ( $newInventoryCountInformations as $key => $value ) {

                      $TempInventoryCountInformation->unique_id = $value['unique_id'];
                      $TempInventoryCountInformation->company_id = $value['company_id'];
                      $TempInventoryCountInformation->account_name = $value['account_name'];
                      $TempInventoryCountInformation->account_code = $value['account_code'];
                      $TempInventoryCountInformation->branch_name = $value['branch_name'];
                      $TempInventoryCountInformation->branch_code = $value['branch_code'];
                      $TempInventoryCountInformation->deleted = $value['deleted'];
                      $TempInventoryCountInformation->from_company_id = $selected_principal_company_id;
                      $TempInventoryCountInformation->deleted_by_user_id = $value['deleted_by_user_id'];
                      $TempInventoryCountInformation->created = $value['unique_id'];
				}
                    $TempInventoryCountInformation->original_id = $informationId;
                    $TempInventoryCountInformation->transferred = 0;
                    $TempInventoryCountInformation->transferred_id = 0;
                    $TempInventoryCountInformation->counterpart_account_code = $counterpartAccountCode['code'];
                    $TempInventoryCountInformation->counterpart_branch_code = $counterpartBranchCode['code'];
                    
                    if(!$tempInventoryCountInformationsTbl->save($TempInventoryCountInformation)){ 
                        $errorCount++;
                        continue ;
                    }

            } 
            
			$hasProductError = false;
			foreach ($items as $itemKey => $item) {
                    foreach ( $item as $key => $val ) {

                        $tempInventoryCountItemsConditions = [
                            'from_company_id' => $selected_principal_company_id,
                            'original_id' => $val['id'],
                            'inventory_count_info_unique_id' => $val['inventory_count_info_unique_id']
                        ];
        
                        $doExistsTempInventoryCountItems = $this->doExists ( $tempInventoryCountItemsConditions, $tempInventoryCountItemsTbl );
                        
                        $accountCode = $val['account_code'];
                        $branchCode = $val['branch_code'];
                        $productCode = $val['product_code'];
                        $productUom = $val['uom'];
                        
                        $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal_company_id, $accountCode, 1);
                        $counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal_company_id, $branchCode, 2);
                        $counterpartProductCode = $this->getCounterpartProductCode( $company_id, $selected_principal_company_id, $productCode );
                        $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal_company_id, $productCode, $productUom);
	
                        //skip iteration
                        if($counterpartAccountCode == "" || $counterpartProductCode == "" || $counterpartBranchCode == "" || $counterpartProductUom == ""){
                            
                            if($counterpartAccountCode == ""){ array_push($errorAccount, $accountCode); }
                            if($counterpartProductCode == ""){ 
                                 array_push($errorProduct, $productCode); 
                                //  continue;

                            }
                            if($counterpartBranchCode == ""){ array_push($errorBranch, $branchCode); }

                            $hasProductError = true;
                            continue;
                        }

                        $itemId = $val['id'];
                        unset($val['id']);

                        if( !$doExistsTempInventoryCountItems ){
                            
                            foreach ( $items as $key => $value ) {
                                foreach( $value as $key => $val ) {

                                    $TempInventoryCountItem = $tempInventoryCountItemsTbl->newEntity();
                                    
                                    $TempInventoryCountItem->inventory_count_info_unique_id = $val['inventory_count_info_unique_id'];
                                    $TempInventoryCountItem->inventory_location = $val['inventory_location'];
                                    $TempInventoryCountItem->product_name = $val['product_name'];
                                    $TempInventoryCountItem->product_item_code = $val['product_code'];
                                    $TempInventoryCountItem->uom = $val['uom'];
                                    $TempInventoryCountItem->price_with_tax = $val['price_with_tax'];
                                    $TempInventoryCountItem->price_without_tax = $val['price_without_tax'];
                                    $TempInventoryCountItem->beginning_inventory = $val['beginning_inventory'];
                                    $TempInventoryCountItem->transfer_in = $val['transfer_in'];
                                    $TempInventoryCountItem->transfer_out = $val['transfer_out'];
                                    $TempInventoryCountItem->stock_availability = $val['stock_availability'];
                                    $TempInventoryCountItem->stock_weight = $val['stock_weight'];
                                    $TempInventoryCountItem->selected_date = isset($val['selected_date']) ? $val['selected_date'] : date('Y-m-d');
                                    $TempInventoryCountItem->account_code = $val['account_code'];
                                    $TempInventoryCountItem->branch_code = $val['branch_code'];
                                    $TempInventoryCountItem->true_offtake = $val['true_offtake'];
                                    $TempInventoryCountItem->deleted = $val['deleted'];
                                    $TempInventoryCountItem->inventory_count_info_unique_id = $val['inventory_count_info_unique_id'];
                                    $TempInventoryCountItem->original_id = $itemId;
                                    $TempInventoryCountItem->from_company_id = $selected_principal_company_id;
                                    $TempInventoryCountItem->transferred = 0;
                                    $TempInventoryCountItem->transferred_id = 0;
                                    $TempInventoryCountItem->counterpart_account_code = $counterpartAccountCode;
                                    $TempInventoryCountItem->counterpart_branch_code = $counterpartBranchCode;
                                    $TempInventoryCountItem->counterpart_product_code = $counterpartProductCode;
                                    $TempInventoryCountItem->counterpart_product_uom = $counterpartProductUom;

                                    if(!$tempInventoryCountItemsTbl->save( $TempInventoryCountItem )){ 
                                        $errorCount++;
                                        continue;
                                    } 
                                }
                            } 
        
                        }
                    }

                    if($hasProductError){
                        $errorCount++;
                    }

			}

		}

		if(count($errorAccount) > 0){
			$err = join(", ", array_unique($errorAccount));
			array_push($errorMessage, "No code mapping for Account code : " . $err);
		}

		if(count($errorBranch) > 0){
			$err = join(", ", array_unique($errorBranch));
			array_push($errorMessage, "No code mapping for Branch code : " . $err);
		}

		if(count($errorProduct) > 0){
			$err = join(", ", array_unique($errorProduct));
			array_push($errorMessage, "No code mapping for Product code : " . $err);
		}

		if(count($errorMessage) > 0){
			$error['message'] = join("<br>", $errorMessage);
		}		
		$error['count'] = $errorCount;
        return $error;	
        
        exit();
    }

    public function getCounterpartProductUom ( $company_id, $selected_principal_company_id, $productCode, $productUom )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $principalConnection = ConnectionManager::get('client_' . $selected_principal_company_id);
        $productUomCodeMappingsTbl = TableRegistry::get('product_uom_code_mappings', ['connection' => $connection]);
        $productUomCodeMappingsPrincipalTbl = TableRegistry::get('ProductUomCodeMappings', ['connection' => $principalConnection]);

        $where = [
            'company_id' => $company_id,
            'counterpart_uom' => $productUom,
            'counterpart_code' => $productCode,
            'to_company_id' => $selected_principal_company_id,
            'deleted' => 0
        ];

        $counterPartUom = $productUomCodeMappingsTbl->find()->where($where)->first();
        
        if( $counterPartUom ) {
            return $counterPartUom['product_uom'];
        }
        
        
        // if not found in this EMS try to look to its connected ems

        $where2 = [
            'company_id' => $selected_principal_company_id,
            'counterpart_uom' => $productUom,
            'counterpart_code' => $productCode,
            'to_company_id' => $company_id,
            'deleted' => 0
        ];
        
        $counterPartUom2 = $productUomCodeMappingsPrincipalTbl->find()->where($where2)->first();
        
        if( $counterPartUom2 ) {
            return $counterPartUom2['product_uom'];
        }

		// if no record found just return empty string
		return "";
    }

    public function getProductUom( $productCode, $company_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $productUomsTbl = TableRegistry::get('product_uoms', ['connection' => $connection]);

        $productCode = $productUomsTbl->find()
                        ->where([
                            'product_code' => $productCode,
                            'company_id' => $company_id
                        ])
                        ->first();

        return isset($productCode) ? $productCode['uom'] : '';               
    }

    public function getCounterpartProductCode( $company_id, $selected_principal_company_id, $productCode )
    {

        $connection = ConnectionManager::get('client_' . $company_id);
        $productUomCodeMappingsTbl = TableRegistry::get('product_uom_code_mappings', ['connection' => $connection]);

        $where = [
            'company_id' => $company_id,
            'counterpart_code' => $productCode,
            'to_company_id' => $selected_principal_company_id,
            'deleted' => 0
        ];
        
        $counterPartCode = $productUomCodeMappingsTbl->find()->where($where)->first();
        
        if( $counterPartCode ) {
            return $counterPartCode['product_code'];
        }
    }

    public function getCounterpartCodes( $company_id, $selected_principal_company_id, $code, $type )
    {
        $this->autoRender = false;
        $connection = ConnectionManager::get('client_' . $company_id);
        $principal_connection = ConnectionManager::get('client_' . $selected_principal_company_id); 
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);
        $principalMultiPrincipalUploadedCodesTbl = TableRegistry::get('multiPrincipalUploadedCodes', ['connection' => $principal_connection]);
        
        $counterPartCode = $multiPrincipalUploadedCodesTbl->find()
                        ->where([
                            'company_id' => $company_id,
                            'counterpart_code' => $code,
                            'to_company_id' => $selected_principal_company_id,
                            'type' => $type,
                            'deleted_status' => 0
                        ])
                        ->first();

        if( $counterPartCode ) {
            return $counterPartCode;
        }     
        
        $principalCounterPartCode = $principalMultiPrincipalUploadedCodesTbl->find()
                                ->where([
                                    'company_id' => $selected_principal_company_id,
                                    'code' => $code,
                                    'to_company_id' => $company_id,
                                    'type' => $type,
                                    'deleted_status' => 0
                                ])
                                ->first();

        if( $principalCounterPartCode ) {
            return $principalCounterPartCode;
        }
        
        // return empty strings if there is no data in both table (DB).
        return "";

    }

    public function getProductCounterPartCodes( $source_company_id, $principal_company_id, $product_code )
    {
        $connection = ConnectionManager::get('client_' . $source_company_id);
        $principal_connection = ConnectionManager::get('client_' . $principal_company_id); 

        $product_uom_code_mappings_table = TableRegistry::get('product_uom_code_mappings', ['connection' => $connection]);
        $principal_product_uom_code_mappings_table = TableRegistry::get('ProductUomCodeMappings', ['connection' => $principal_connection]);

        $counterpart_code = "";

        $data = $product_uom_code_mappings_table->find()
                ->where([
                    'company_id' => $source_company_id,
                    'to_company_id' => $principal_company_id,
                    'counterpart_code' => $product_code,
                    'deleted' => 0
                ])
                ->first();

        if( $data ) {
            $counterpart_code = $data['product_code'];
        }        


        $data2 = $principal_product_uom_code_mappings_table->find()
                ->where([
                    'company_id' => $principal_company_id,
                    'product_code' => $product_code,
                    'to_company_id' => $source_company_id,
                    'deleted' => 0
                ])
                ->first();

        if( !empty($data2) ) {
            $counterpart_code = $data['product_code'];
        }        
        
        $test = [
            'company_id' => $source_company_id,
            'to_company_id' => $principal_company_id,
            'counterpart_code' => $product_code,
            'deleted' => 0
        ];

        return $counterpart_code;

    }

    public function doExists( $condition, $table )
    {
        $doExists = $table->find()
                    ->where($condition)
                    ->first();
                    
        return !empty($doExists) == true ? $doExists : [];            
    }   

    public function inventoryCountItems ( $inventoryCountInformations, $company_id, $inventoryCountItemsTbl )
    {
        $to_return = [];

        foreach( $inventoryCountInformations as $key => $value ) {
            $arr = [];
            $arr['InventoryCountInformation'][] = $value;

            $where = [
                'inventory_count_ems_items.company_id' => $company_id,
                'inventory_count_ems_items.deleted' => 0,
                'inventory_count_ems_items.inventory_count_info_unique_id' => $value['unique_id'],
            ];

            $join = [
                'table' => 'multi_principal_principal_product_settings',
                'alias' => 'MultiPrincipalPrincipalProductSetting',
                'type' => 'INNER',
                'conditions' => array(
                    'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
                    'MultiPrincipalPrincipalProductSetting.item_code = inventory_count_ems_items.product_code',
                    'MultiPrincipalPrincipalProductSetting.status' => 1
                    )
            ];

            $inventoryCountItems = $inventoryCountItemsTbl->find()
            ->where($where)
            ->join($join)
            ->toArray();

            $arr['InventoryCountItems'][] = $inventoryCountItems;

            $to_return = $arr;
        }

        return !empty($to_return) == true ? $to_return : [];
    }
    
    public function transferTempIC()
    {
        // transfer temporary data to actual table
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $inventoryCountInformations = $this->getAllInventoryCount( $start_date, $end_date, $company_id, $index );
        $savedToInventoryCount = $this->saveToInventoryCount( $inventoryCountInformations, $company_id, $selected_principal );
        
        
	 	echo json_encode ($savedToInventoryCount['transferred']);

    }

    public function saveToInventoryCount( $inventoryCountInformations, $company_id, $selected_principal )
    {
        $this->autoRender = false;  
        $error = array();
		$error['message'] = '';
		$error['count'] = 0;
        $error['transferred'] = 0;
        
        $principal_connection = ConnectionManager::get('client_' . $selected_principal); 
        $principalInventoryCountEmsInformationsTbl = TableRegistry::get('inventoryCountEmsInformations', ['connection' => $principal_connection]);
        $principalInventoryCountEmsItemsTbl = TableRegistry::get('inventoryCountEmsItems', ['connection' => $principal_connection]);
        $principalTempInventoryCountInformationsTbl = TableRegistry::get('tempInventoryCountInformations', ['connection' => $principal_connection]);
        $principalTempInventoryCountItemsTbl = TableRegistry::get('tempInventoryCountItems', ['connection' => $principal_connection]);

        $newInventoryCountInformations = $inventoryCountInformations['InventoryCountInformation'];
        
		foreach ( $newInventoryCountInformations as $key => $information ) {
			
			// push to temporary database

			$items = $inventoryCountInformations['InventoryCountItems']; 
			$informationId = $information['id'];
			unset($information['id']);
			unset($information['InventoryCountItems']);
			$information['company_id'] = $company_id;


			$informationCondition = array(
				'original_id' => $informationId,
				'unique_id' => $information['unique_id'],
				'from_company_id' => $company_id, //$company_id,
				'transferred' => 0
            );
            
            
            $doTempInformationExist = $this->doExists( $informationCondition, $principalTempInventoryCountInformationsTbl );
            
			if( $doTempInformationExist ){

                foreach ( $newInventoryCountInformations as $key => $value ) {
                    
                    $InventoryCountInformation = $principalInventoryCountEmsInformationsTbl->newEntity(); 

                    if ( $value['account_code'] ) {
                        $account_code = $doTempInformationExist['counterpart_account_code'];
                        $InventoryCountInformation->account_code = $account_code;
                    }
                    
                    if( $value['branch_code'] ) {
                        $branch_code = $doTempInformationExist['counterpart_branch_code'];
                        $InventoryCountInformation->branch_code =  $branch_code;
                    }
                    
                    $InventoryCountInformation->company_id = $value['company_id'];
                    $InventoryCountInformation->unique_id = $value['unique_id'];
                    $InventoryCountInformation->account_name = $value['account_name'];
                    $InventoryCountInformation->branch_name = $value['branch_name'];
                    $InventoryCountInformation->username = $value['username'];
                    $InventoryCountInformation->user_fname = "";
                    $InventoryCountInformation->user_lname = "";
                    $InventoryCountInformation->date_created = $value['date_created'];
                    $InventoryCountInformation->deleted = $value['deleted'];
                    $InventoryCountInformation->date_deleted = $value['date_deleted'];
                    $InventoryCountInformation->deleted_by_name = $value['deleted_by_name'];
                    $InventoryCountInformation->deleted_by_username = $value['deleted_by_username'];
                    $InventoryCountInformation->deleted_by_user_id = $value['deleted_by_user_id'];
                    $InventoryCountInformation->created = $value['created'];
                    $InventoryCountInformation->modified = $value['modified']; 

                    $ifSaved = $principalInventoryCountEmsInformationsTbl->save($InventoryCountInformation);
                    
                    if( !$ifSaved ){ 
                        $error['count']++; 
                        continue;
                    }

                    // update temp table transferred column and date 
                    $transferred_id =$ifSaved['id'];
                    $updateSet =  array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $transferred_id); 
                    $updateAll = $principalTempInventoryCountInformationsTbl->updateAll($updateSet, $informationCondition);
                    
                    if( !$updateAll ) {
                        $error['count']++; 
					    continue;
                    }

                    foreach ( $items as $itemKey => $item ) {
                        
                        foreach( $item as $key => $val ) {

                            $itemId = $val['id'];
                            unset($val['id']);
                            $val['company_id'] = $company_id;

                            $itemCondition = array(
                                'from_company_id' => $company_id, //$company_id,
                                'original_id' => $itemId,
                                'inventory_count_info_unique_id' => $val['inventory_count_info_unique_id']
                            );
    
                            $doTempItemsExist = $this->doExists( $itemCondition, $principalTempInventoryCountItemsTbl );
    
                            if( $doTempItemsExist && $doTempInformationExist ) {


                                $product_code = $doTempItemsExist['counterpart_product_code'];
                                $product_uom = $doTempItemsExist['counterpart_product_uom'];
                                $account_code = $doTempItemsExist['counterpart_account_code'];
                                $branch_code = $doTempItemsExist['counterpart_branch_code'];

                                $inventoryCountEmsItems = $principalInventoryCountEmsItemsTbl->newEntity();

                                $inventoryCountEmsItems->company_id = $val['company_id'];
                                $inventoryCountEmsItems->inventory_count_info_unique_id = $val['inventory_count_info_unique_id'];
                                $inventoryCountEmsItems->inventory_location = $val['inventory_location'];
                                $inventoryCountEmsItems->product_name = $val['product_name'];
                                $inventoryCountEmsItems->product_code = $product_code;
                                $inventoryCountEmsItems->uom = $product_uom;
                                $inventoryCountEmsItems->price_with_tax = $val['price_with_tax'];
                                $inventoryCountEmsItems->price_without_tax = $val['price_without_tax'];
                                $inventoryCountEmsItems->beginning_inventory = $val['beginning_inventory'];
                                $inventoryCountEmsItems->transfer_in = $val['transfer_in'];
                                $inventoryCountEmsItems->transfer_out = $val['transfer_out'];
                                $inventoryCountEmsItems->stock_availabilty = $val['stock_availability'];
                                $inventoryCountEmsItems->stock_weight = $val['stock_weight'];
                                $inventoryCountEmsItems->selected_date = isset($val['selected_date']) ? $val['selected_date'] : date('Y-m-d H:i:s');
                                $inventoryCountEmsItems->account_code = $account_code;
                                $inventoryCountEmsItems->branch_code = $branch_code;
                                $inventoryCountEmsItems->true_offtake = $val['true_offtake'];
                                $inventoryCountEmsItems->deleted = $val['deleted'];
                                $inventoryCountEmsItems->created = $val['created'];
                                $inventoryCountEmsItems->modified = $val['modified'];

                                $ifInserted = $principalInventoryCountEmsItemsTbl->save($inventoryCountEmsItems);
                                if( !$ifInserted ) {
                                    $error['count']++; 
							        continue;
                                }

                                $transferred_id = $ifInserted['id']; 
                                $updateSet =  array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $transferred_id);  
                                $updateAll = $principalTempInventoryCountItemsTbl->updateAll($updateSet, $itemCondition); 
                                
                                if( !$updateAll ) {
                                    $error['count']++; 
                                    continue;
                                }
                           }

                        }

                    }

                }    

				$error['transferred']++;

			}			

		}

		return $error;
    }

    public function principalSettings()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
    }

    public function principalSettingsDataTable ()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalPrincipalSettingsTbl = TableRegistry::get('multi_principal_principal_settings', ['connection' => $connection]);
        $multiPrincipalPrincipalSettings = $multiPrincipalPrincipalSettingsTbl->principalSettingsDataTable($company_id, $_POST);

        echo json_encode($multiPrincipalPrincipalSettings);
        exit();

    }

    public function principalProducts ()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalPrincipalSettingsTbl = TableRegistry::get('multi_principal_principal_settings', ['connection' => $connection]);
        $principalProductId = $_POST['id']; 
        $principalProductCode = $multiPrincipalPrincipalSettingsTbl->getPrincipalProductCode( $company_id, $principalProductId );
        
        $result = $multiPrincipalPrincipalSettingsTbl->getPrincipalProducts( $company_id, $principalProductCode, $_POST );
        echo json_encode($result);
        exit;
    }

    public function assignProducts()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalPrincipalSettingsTbl = TableRegistry::get('multi_principal_principal_settings', ['connection' => $connection]);
        $postData = $_POST;
        
        $result = $multiPrincipalPrincipalSettingsTbl->insertMultiplePrincipalProductSettings( $company_id, $postData );
        echo json_encode($result);
        exit;
    }

    public function assignAllProducts()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalPrincipalSettingsTbl = TableRegistry::get('multi_principal_principal_settings', ['connection' => $connection]);
        $principalProductId = $_POST['id'];
        $principalProductStatus = $_POST['status'];
        $principalProductCode = $multiPrincipalPrincipalSettingsTbl->getPrincipalProductCode( $company_id, $principalProductId );
        
        $result = $multiPrincipalPrincipalSettingsTbl->insertAllMultiplePrincipalProductSettings( $company_id, $principalProductCode, $principalProductStatus );
        echo json_encode($result);
        exit;
    }

    public function UserSettings()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 
        $this->set('title', 'User Settings');
    }

    public function userSettingsDataTable()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUserSettingsTbl = TableRegistry::get('multi_principal_user_settings', ['connection' => $connection]);
        $multiPrincipalUserSettings = $multiPrincipalUserSettingsTbl->principalUserSettingsDataTable($company_id, $_POST);

        echo json_encode($multiPrincipalUserSettings);
        exit();
    }

    public function transferUser()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUserSettingsTbl = TableRegistry::get('multi_principal_user_settings', ['connection' => $connection]);
        $result = $multiPrincipalUserSettingsTbl->InsertUserSettings($company_id, $_POST);

        echo json_encode($result);
        exit();
    }

    public function codeMapping()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 
        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        if( $company_id == "200986" ) {
            $companies_array = ['200987', '200988'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000342" || $company_id == "000357" ) {
            $companies_array = ['000755'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000628" ) {
            $companies_array = ['001006' ];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else if( $company_id == "000255" ) {
            $companies_array = ['000755', '100219'];
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipalsByCompanyName( $companies_array );
        } else {
            $principals = $multiPrincipalUploadedCodesTbl->getPrincipals( $company_id );
        }


        
        $this->set('title', 'Code Mapping');
        $this->set('principals', $principals);


    }

    public function UploadCsvCodeMapping()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $records = $_POST['file'];
        $index = intval($_POST['index']);
        $function_type = $_POST['functions']; 
        $principals = $_POST['principals'];
        
        $lineCount = $index > 0 ? (20 * $index )+ 2: 2;
        $details = [];
        
        foreach($records as $record){
            $row = str_getcsv($record, ',','"'); 

            $data['company_id'] = $company_id;

            // FOR USER MAPPING CSV 
            if( $function_type == "user_mapping" ) {

                $data['name'] = !empty($row[0]) ? $row[0] : ''; // MAKE USERNAME AS NAME. 
                $data['code'] = !empty($row[0]) ? $row[0] : ''; // CODE IS USERNAME
                $data['counterpart_code'] = !empty($row[1]) ? $row[1] : ''; // COUNTERPART CODE 

            } elseif( $function_type == "product_codes" ) {

                $data['name'] = !empty($row[0]) ? $row[0] : '';
                $data['code'] = !empty($row[1]) ? $row[1] : '';
                $data['product_uom'] =  !empty($row[2]) ? $row[2] : ''; 
                $data['counterpart_product_uom'] = !empty($row[3]) ? $row[3] : '';
                $data['counterpart_product_code'] = !empty($row[4]) ? $row[4] : '';
                $data['counterpart_code'] = !empty($row[4]) ? $row[4] : ''; // COUNTER PART PRODUCT CODE

            } else {

                $data['name'] = !empty($row[0]) ? $row[0] : '';
                $data['code'] = !empty($row[1]) ? $row[1] : '';
                $data['counterpart_code'] = !empty($row[2]) ? $row[2] : '';

            }
            

            $data['function_type'] = $function_type;
            $data['principals'] = $principals; 
            
            $result = $multiPrincipalUploadedCodesTbl->saveCodeMapping($data);
            $details[$lineCount] = $result;
            $lineCount++;
        }
        
        echo json_encode($details);

        exit();
    }

    public function downloadAllTemplate()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $function_type = $_POST['function_type'];
        $limit = $_POST['limit'];
        $principals = $_POST['principals'];

        $connection = ConnectionManager::get('client_' . $principals); 
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]); 
        $accountsTbl = TableRegistry::get('accounts', ['connection' => $connection]);
        $branchesTbl = TableRegistry::get('branches', ['connection' => $connection]);
        $fieldExecutionFormsTbl = TableRegistry::get('field_execution_forms', ['connection' => $connection]);
        $fieldExecutionElementsTbl = TableRegistry::get('field_execution_elements', ['connection' => $connection]);
        $fieldExecutionStandardsTbl = TableRegistry::get('field_execution_standards', ['connection' => $connection]);
        $productsTbl = TableRegistry::get('products', ['connection' => $connection]);
        $noOrderReasonsTbl = TableRegistry::get('no_order_reasons', ['connection' => $connection]);
        $visitStatusTbl = TableRegistry::get('visit_status', ['connection' => $connection]);
        $warehouseTbl = TableRegistry::get('warehouses', ['connection' => $connection]);
        $usersTbl = TableRegistry::get('users');

        if( $function_type == 'account_codes' ) {
           
            $where_cond = [
                'company_id' => $principals,
                'deleted' => 0
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownload( $where_cond, $accountsTbl ); 

        } else if ( $function_type == 'branch_codes' ) {

            $where_cond = [
                'company_id' => $principals,
                'deleted' => 0
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownload( $where_cond, $branchesTbl ); 

        } else if ( $function_type == 'field_execution_forms' ) {

            $where_cond = [
                'company_id' => $principals,
                'deleted_status' => 0
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownload( $where_cond, $fieldExecutionFormsTbl ); 

        } else if ( $function_type == 'field_execution_elements' ) {

            $where_cond = [
                'company_id' => $principals,
                'deleted_status' => 0,
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownloadElements( $where_cond, $fieldExecutionElementsTbl ); 

        } else if ( $function_type == 'field_execution_standards' ) {
           
            $where_cond = [
                'company_id' => $principals,
                'deleted_status' => 0,
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownloadStandards( $where_cond, $fieldExecutionStandardsTbl ); 

        } else if ( $function_type == 'product_codes' ) {
           
            $where_cond = [
                'company_id' => $principals,
                'deleted' => 0,
                'inactive' => 0,
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownloadProducts( $where_cond, $productsTbl, $principals ); 
            
        } else if ( $function_type == 'user_mapping' ) {  

            $where_cond = [
                'company_id' => $principals,
                'user_type_id' => 3,
                'deleted' => 0,
                'active' => 1,
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownloadUser( $where_cond, $usersTbl );

        } else if ( $function_type == 'visit_status_codes' ) {
           

            $where_cond = [
                'company_id' => $principals,
                'deleted' => 0,
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownloadVisit( $where_cond, $visitStatusTbl );

        } else {

            // $function_type == 'warehouse_codes'

            
            $where_cond = [
                'company_id' => $principals,
                'deleted' => 0
            ];

            $data = $multiPrincipalUploadedCodesTbl->getAllDataToDownload( $where_cond, $warehouseTbl );
        }
        echo json_encode($data);
        exit;
    }

    public function viewCodeMapping()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $function = $_POST['functions'];
         // TYPES :
        // account = 1, branch = 2, warehouse = 3, products = 4, reason = 5, field_ex = 6, field_ex_elements = 7, field_ex_standard = 8, booking_approval_status = 9, user_mapping = 10


        if( $function == 'account_codes' ) {
            $type = 1;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else if( $function == 'branch_codes' ) {
            $type = 2;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else if ( $function == 'field_execution_forms' ) {
            $type = 6;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else if ( $function == 'field_execution_elements' ) {
            $type = 7;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else if ( $function == 'field_execution_standards' ) {
            $type = 8;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else if ( $function == 'user_mapping' ) {  
            $type = 10;
            $result = $multiPrincipalUploadedCodesTbl->getUserUploadedCodes( $company_id, $_POST, $type );
        } else if ( $function == 'visit_status_codes' ) {
            $type = 5;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        } else {
            // $function_type == 'warehouse_codes'
            $type = 3;
            $result = $multiPrincipalUploadedCodesTbl->getUploadedCodes( $company_id, $_POST, $type );
        }

        echo json_encode($result);
        exit();
    }

    public function deleteCodeMapping ()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $id = $_POST['id'];

        $result = $multiPrincipalUploadedCodesTbl->deleteCodeMapping( $company_id, $multiPrincipalUploadedCodesTbl, $id );

        echo json_encode($result);
        exit();
    }

    public function viewProductCodeMapping()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $connection = ConnectionManager::get('client_' . $company_id);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $function = $_POST['functions'];
        $result = $multiPrincipalUploadedCodesTbl->getProductUploadedCodes( $company_id, $_POST );
        echo json_encode($result);
        exit();
    }
    
    public function deleteUploadedProductCode ()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $connection = ConnectionManager::get('client_' . $company_id);
        $producTbl = TableRegistry::get('product_uom_code_mappings', ['connection' => $connection]);
        $multiPrincipalUploadedCodesTbl = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $id = $_POST['id'];

        $result = $multiPrincipalUploadedCodesTbl->deleteUploadedProductCode( $company_id, $producTbl, $id );

        echo json_encode($result);
        exit();
    }

    public function countAllVAOF()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 

        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_user_settings_tbl = TableRegistry::get('multi_principal_user_settings', ['connection' => $connection]);
        $van_account_transaction_tbl = TableRegistry::get('van_account_transactions', ['connection' => $connection]);

        $startDate = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
        $endDate = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';
        
        $usernames = $multi_principal_user_settings_tbl->getAllUsername($company_id);
        $usernames = $multi_principal_user_settings_tbl->getValidatedUser( $company_id, $principal, $usernames );
        $branch_codes = $this->getValidatedBranches($company_id, $principal);
        $account_codes = $this->getValidatedAccounts($company_id, $principal);
        $product_codes = $this->getMappedAndAssignedProducts( $company_id, $principal );
        
        $result = [];
        $result['message'] = [];
        $result['total'] = 0;

        $count_username = count( $usernames );
        $count_branch_code = count( $branch_codes );
        $count_account_codes = count( $account_codes );
        $count_product_codes = count( $product_codes );

        if( $count_username == 0 ) {
            $result['message'][] = "No Username Found in Mapping";
        }

        if( $count_branch_code == 0 ) {
            $result['message'][] = "No Branches Found in Mapping";
        }

        if( $count_account_codes == 0 ) {
            $result['message'][] = "No Accounts Found in Mapping";
        }

        if( $count_product_codes == 0 ) {
            $result['message'][] = "No Products Found in Mapping";
        }
        
        if( empty($result['message']) || $result['message'] == "" ) {

            $group = [
                'van_account_transactions.transaction_id'
            ];
    
            $where = [
                "van_account_transactions.transaction_date BETWEEN '". $startDate." 00:00:00' AND '". $endDate ." 23:59:59'",
                'van_account_transactions.company_id' => $company_id,
                "van_account_transactions.username IN" => $usernames,
                'van_account_transactions.branch_code IN' => $branch_codes,
                'van_account_transactions.account_code IN' => $account_codes,
                'VanAccountItem.product_code IN' => $product_codes,
            ];
    
            $joinVanAccountItems = [
                'table' => 'van_account_items',
                'alias' => 'VanAccountItem',
                'type' => 'INNER',
                'conditions' => array(
                    'VanAccountItem.transaction_id = van_account_transactions.transaction_id',
                    'VanAccountItem.company_id' => $company_id
                )
            ];
    
            $joinMultiPrincipalProductSet = [
                'table' => 'multi_principal_principal_product_settings',
                'alias' => 'MultiPrincipalPrincipalProductSetting',
                'type' => 'INNER',
                'conditions' => array(
                    'MultiPrincipalPrincipalProductSetting.item_code = VanAccountItem.product_code',
                    'MultiPrincipalPrincipalProductSetting.status' => 1,
                    'MultiPrincipalPrincipalProductSetting.company_id' => $company_id
                )
            ];
    
            $totalVanAccountTransactions = $van_account_transaction_tbl->find()
                                           ->group($group)
                                           ->where($where)
                                           ->join($joinVanAccountItems)
                                           ->join($joinMultiPrincipalProductSet)
                                           ->count();

            $result['total'] = $totalVanAccountTransactions;
        }

        if(  $totalVanAccountTransactions == 0 ) {
            $result['message'][] = "No Vaof Data in Selected Date.";
        }
                                       
        echo json_encode ( $result ); 
        exit;
    }

    public function pushDataToTempVaof()
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_principal_product_settings_tbl = TableRegistry::get('multi_principal_principal_product_settings', ['connection' => $connection]);
        
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $assigned_principal_products = $this->getAllProductCode( $company_id, $multi_principal_principal_product_settings_tbl );

        $transactions = $this->getAllVaof( $start_date, $end_date, $company_id, $index, $assigned_principal_products );
        $saveToTemp = $this->saveVaofToTemp($transactions, $company_id, $selected_principal);

        echo json_encode($saveToTemp);	

    }

    public function transferTempVaof()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
		

        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_principal_product_settings_tbl = TableRegistry::get('multi_principal_principal_product_settings', ['connection' => $connection]);
        
        $hasError = false;
        $errorMessage = array();

        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : die('Invalid start date');
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : die('Invalid end date');
        $selected_principal = isset($_POST['principal']) ? $_POST['principal'] : die('Invalid principal');
        $index = isset($_POST['index']) ? $_POST['index'] : die('Invalid index');

        $assigned_principal_products = $this->getAllProductCode( $company_id, $multi_principal_principal_product_settings_tbl );

        $transactions = $this->getAllVaof($start_date, $end_date, $company_id, $index, $assigned_principal_products);
        $savedToVaof = $this->saveToVaof($transactions, $company_id, $selected_principal);
        
        echo json_encode($savedToVaof);
        exit;
    }

    public function saveToVaof($transactions, $company_id, $selected_principal)
    {
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $principal_connection = ConnectionManager::get('client_' . $selected_principal);

        $van_account_transactions_tbl = TableRegistry::get('VanAccountTransactions', ['connection' => $principal_connection]);
        $van_account_collections_tbl = TableRegistry::get('VanAccountCollections', ['connection' => $principal_connection]);
        $van_account_not_ordered_items_tbl = TableRegistry::get('vanAccountNotOrderedItems', ['connection' => $principal_connection]);
     
        
        $temp_van_account_transactions_tbl = TableRegistry::get('temp_van_account_transactions', ['connection' => $principal_connection]);
        $temp_van_account_collections_tbl = TableRegistry::get('temp_van_account_collections', ['connection' => $principal_connection]);
        $temp_van_account_not_ordered_items_tbl = TableRegistry::get('temp_van_account_not_ordered_items', ['connection' => $principal_connection]);
        
        $result = [];
        $result['message'] = [];
        $result['count'] = 0;
        $result['transferred'] = 0;
        $date = date('Y-m-d h:i:s');

        $error = array();
		$error['message'] = '';
		$error['count'] = 0;
		$error['transferred'] = 0;
      
        $principal_connection->begin();
        
        foreach ($transactions as $key => $transaction) {

			$data = json_decode(json_encode($transactions[$key]), true); 
            
            $username = $transaction['username'];
			$orignal_id = $transaction['id'];
			$transaction['company_id'] = $selected_principal;

			$vanAccountCollections = $transaction['VanAccountCollections'];
			$vanAccountItems = $transaction['VanAccountItems'];
			$varCollections = $transaction['VarCollections'];
			$afterEditedVanAccountItems = $transaction['AfterEditedVanAccountItems'];
			$beforeEditedVanAccountItems = $transaction['BeforeEditedVanAccountItems'];
			$cancelledVanAccountCollections = $transaction['CancelledVanAccountCollections'];
			$cancelledVanAccountTransactions = $transaction['CancelledVanAccountTransactions'];
			$cancelledVanAccountTransactionItems = $transaction['CancelledVanAccountTransactionItems'];
			$editedVanAccountTransactions = $transaction['EditedVanAccountTransactions'];
			$vanAccountNotOrderedItems = $transaction['VanAccountNotOrderedItems'];
			$vanAccountServedProducts = $transaction['VanAccountServedProducts'];
			$vanAccountTransactionDeductions = $transaction['VanAccountTransactionDeductions'];
			$vanAccountTransactionEditCodes = $transaction['VanAccountTransactionEditCodes'];
			$vanAccountTransactionEditRequests = $transaction['VanAccountTransactionEditRequests'];

			$transactionCondition = array(
				'original_id' => $orignal_id,
				'from_company_id' => $company_id,
				'company_id' => $selected_principal,
				'transferred' => 0
			);
            
			$doExist = $temp_van_account_transactions_tbl->find()->where($transactionCondition)->first();
              
			if($doExist){
    
				$warehouse_code = $doExist['warehouse_code'];
				$userAssignedWarehouse = $this->getUserAssignedToWarehouse($warehouse_code, $company_id, $username);
                $counterpart_username = $this->getCounterpartCodes( $company_id, $selected_principal, $username, 10 );
                $counterpart_warehouse_code = $this->getCounterpartCodes( $company_id, $selected_principal,  $transaction['warehouse_code'], 3 );

				if(!$userAssignedWarehouse){
                    $result['message'][] = "Username {$username} is not assigned in warehouse code  {$warehouse_code}";
					$error['count']++; 
					continue;
				}

                unset($data['id']);
                unset($data['VanAccountCollections']);
                unset($data['VanAccountItems']);
                unset($data['VarCollections']);
                unset($data['AfterEditedVanAccountItems']);
                unset($data['BeforeEditedVanAccountItems']);
                unset($data['CancelledVanAccountCollections']);
                unset($data['CancelledVanAccountCollections']);
                unset($data['CancelledVanAccountTransactions']);
                unset($data['CancelledVanAccountTransactionItems']);
                unset($data['EditedVanAccountTransactions']);
                unset($data['VanAccountNotOrderedItems']);
                unset($data['VanAccountServedProducts']);
                unset($data['VanAccountTransactionDeductions']);
                unset($data['VanAccountTransactionEditCodes']);
                unset($data['VanAccountTransactionEditRequests']);

                $van_account_transactions = $van_account_transactions_tbl->newEntity();

                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id';
                            $van_account_transactions->$key = $selected_principal;
                        break;
                        case 'username':
                            $van_account_transactions->$key =  $counterpart_username['code'];
                        break;
                        case 'warehouse_code':
                            $van_account_transactions->$key = $counterpart_warehouse_code['code'];
                        break;
                        case 'account_code':
                            $van_account_transactions->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'account_name':
                            $account_name = $this->getAccountNameByAccountCode( $doExist['counterpart_account_code'], $selected_principal );
                            $van_account_transactions->$key =$account_name;
                        break;
                        case 'branch_code':
                            $van_account_transactions->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'branch_name':
                            $branch_name = $this->getBranchNameByBranchCode( $doExist['counterpart_branch_code'], $selected_principal );
                            $van_account_transactions->$key = $branch_name;
                        break;  
                        default:
                            $van_account_transactions->$key = $value;
                        break;
                    }

                }

                $doInsert = $van_account_transactions_tbl->save($van_account_transactions);
                               
				if(!$doInsert){ 
                    $principal_connection->rollback();
                    $result['message'][] = "Unable to Transfer Van Account Transactions";
					$error['count']++; 
					continue;
				}

				// update temp table transferred column and date
				$transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$transactionCondition = array('original_id' => $orignal_id);

				if(!$temp_van_account_transactions_tbl->updateAll($updateSet, $transactionCondition)) { 
                    $principal_connection->rollback();
                    $result['message'][] = "Unable to Update Temp Van Account Transactions";
					$error['count']++; 
					continue;
				}
                
				// save all items
                $transferVanAccountCollections = $this->saveVanAccountCollections( $vanAccountCollections, $company_id, $selected_principal, $transferred_id, $userAssignedWarehouse);
         
                if($transferVanAccountCollections['hasError']){
                    $principal_connection->rollback();
                    foreach( $transferVanAccountCollections['message'] as $value ) {
                        $result['message'][] = $value;
                        $error['count']++; 
                    }
                }

                if( $vanAccountItems->count() > 0 ) {

                    $transfetVanAccountItems = $this->saveVanAccountItems($vanAccountItems, $company_id, $selected_principal, $transferred_id);
                    if($transfetVanAccountItems['hasError']){
                        $principal_connection->rollback();
                        foreach( $transfetVanAccountItems['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }

                if( $varCollections->count() > 0 ) {
                    $transferVarAccountCollections = $this->saveVarCollections($varCollections, $company_id, $selected_principal );
                    if($transferVarAccountCollections['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferVarAccountCollections['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }
                
                if( $afterEditedVanAccountItems->count() > 0 ) {
                    $transferSaveAfterEditedVanAccountItems = $this->saveAfterEditedVanAccountItems($afterEditedVanAccountItems, $company_id, $selected_principal);
                    if($transferSaveAfterEditedVanAccountItems['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferSaveAfterEditedVanAccountItems['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }

                if( $beforeEditedVanAccountItems->count() > 0 ) {
                    $transferSaveBeforeEditedVanAccountItems = $this->saveBeforeEditedVanAccountItems($beforeEditedVanAccountItems, $company_id, $selected_principal);
                    if($transferSaveBeforeEditedVanAccountItems['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferSaveBeforeEditedVanAccountItems['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
				}

                if( $cancelledVanAccountCollections->count() > 0 ) {
                    $transferCancelledVanAccountCollections = $this->saveCancelledVanAccountCollections($cancelledVanAccountCollections, $company_id, $selected_principal);
                    if($transferCancelledVanAccountCollections['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferCancelledVanAccountCollections['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
				}
                
                if( $cancelledVanAccountTransactions->count() > 0 ) {
                    $transferCancelledVanAccountTransactions = $this->saveCancelledVanAccountTransactions($cancelledVanAccountTransactions, $company_id, $selected_principal);
                    if($transferCancelledVanAccountTransactions['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferCancelledVanAccountTransactions['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }
                
                if( $cancelledVanAccountTransactionItems->count() > 0 ) {
                    $transferCancelledVanAccountTransactionItems = $this->saveCancelledVanAccountTransactionItems($cancelledVanAccountTransactionItems, $company_id, $selected_principal);
                    if($transferCancelledVanAccountTransactionItems['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferCancelledVanAccountTransactionItems['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }
                
                if( $editedVanAccountTransactions->count() > 0 ) {
                    $transferEditedVanAccountTranscations = $this->saveEditedVanAccountTranscations($editedVanAccountTransactions, $company_id, $selected_principal);
                    if($transferEditedVanAccountTranscations['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferEditedVanAccountTranscations['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }

                // if( $vanAccountNotOrderedItems->count() > 0 ) { 

                //     $tables = [];
                //     $tables['temp_van_account_not_ordered_items_tbl']  = $temp_van_account_not_ordered_items_tbl;            
                //     $tables['van_account_not_ordered_items_tbl']  = $van_account_not_ordered_items_tbl;    
                   
                //     $transferAccountNotOrderedItem = $this->saveAccountNotOrderedItem( $vanAccountNotOrderedItems, $company_id, $selected_principal, $transferred_id, $tables );
                //     $vanAccountNotOrderedItemsEntities = $van_account_not_ordered_items_tbl->newEntities($transferAccountNotOrderedItem);
                //     $saveVanAccountNotOrderedItems = $van_account_not_ordered_items_tbl->saveMany($vanAccountNotOrderedItemsEntities); // SaveEntity
                  
                //     if ( $saveVanAccountNotOrderedItems ) {

                //         foreach( $saveVanAccountNotOrderedItems as $key => $value ) {

                //             $updateSet = array('transferred' => 1, 'transferred_date' => date('Y-m-d H:i:s'), 'transferred_id' => $value['id']);
                //             $updateCondition = array('from_company_id' => $company_id, 'original_id' => $value['original_id'], 'transferred' => 0);
                //             $update = $temp_van_account_not_ordered_items_tbl->updateAll($updateSet, $updateCondition);
                            
                //             if(!$update) { 
                //                 $principal_connection->rollback();
                //                 $result['message'][] = "Van Account Not Ordered Items with ID {$orignal_id} cannot be saved.";
                //                 $error['count']++; 
                //             }
                //         }


                //     } else {
                //         $principal_connection->rollback();
                //         $result['message'][] = "Van Account Not Ordered Items with ID {$orignal_id} cannot be saved.";
                //         $error['count']++; 
                //     }
                // }

                if( $vanAccountServedProducts->count() > 0 ) {
                    $transferVanAccountServedProducts = $this->saveVanAccountServedProducts( $vanAccountServedProducts, $company_id, $selected_principal, $transferred_id );
                    if($transferVanAccountServedProducts['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferVanAccountServedProducts['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }

                if( $vanAccountTransactionDeductions->count() > 0 ) {
                    $transferVanAccountTransactionDeduction = $this->saveVanAccountTransactionDeduction( $vanAccountTransactionDeductions, $company_id, $selected_principal, $transferred_id );
                    if($transferVanAccountTransactionDeduction['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferVanAccountTransactionDeduction['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }

                if( $vanAccountTransactionEditCodes->count() > 0 ) {
                    $transferVanAccountTranscationEditCodes = $this->saveVanAccountTranscationEditCodes( $vanAccountTransactionEditCodes, $company_id, $selected_principal, $transferred_id );
                    if($transferVanAccountTranscationEditCodes['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferVanAccountTranscationEditCodes['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
    
                }

                if( $vanAccountTransactionEditRequests->count() > 0 ) {
                    $transferVanAccountTransactionEditRequests = $this->saveVanAccountTransactionEditRequests( $vanAccountTransactionEditRequests, $company_id, $selected_principal, $transferred_id );
                    if($transferVanAccountTransactionEditRequests['hasError']){
                        $principal_connection->rollback();
                        foreach( $transferVanAccountTransactionEditRequests['message'] as $value ) {
                            $result['message'][] = $value;
                            $error['count']++; 
                        }
                    }
                }
				// return error count

				$error['transferred']++;

			}else{
				$error['transferred'] = 0;
			}		

		}

        
        $principal_connection->commit();
        $result['count'] = $error['count'];
        $result['transferred'] = $error['transferred'];
        
		return $result;

    }
    
    public function saveVanAccountItems($vanAccountItems, $company_id, $selected_principal, $transferred_id)
    {
        $principal_connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_items_tbl = TableRegistry::get('temp_van_account_items', ['connection' => $principal_connection]);
        $van_account_items_table = TableRegistry::get('vanAccountItems', ['connection' => $principal_connection]);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($vanAccountItems as $itemKey => $item) {
	
			$data = json_decode(json_encode($item), true);
            $original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal,
                'transferred' => 0
            ];
            
			$doItemExist = $temp_van_account_items_tbl->find()->where( $cond )->first();
			
			if($doItemExist){

                unset($data['id']);
				$van_account_items = $temp_van_account_items_tbl->newEntity();
                
				foreach ($data as $key => $value) {

					switch ($key) {
						
						case 'product_code':
							$van_account_items->$key = $doItemExist['counterpart_product_code'];
                        break;
                        
						case 'product_name':
                            $product_name = $this->getProductNameByProductCode( $doItemExist['counterpart_product_code'], $selected_principal );
							$van_account_items->$key = $product_name;
                        break;

						case 'uom':
							$van_account_items->$key = $doItemExist['counterpart_product_uom'];
                        break;

						case 'company_id':
							$van_account_items->$key = $selected_principal;
                        break;
						
						default:
                            $van_account_items->$key = $value;
					}

				}
                
                $doInsert = $van_account_items_table->save($van_account_items);

                if( !$doInsert ) {
                    $result['message'][] = "Unable to Transfer Van Account Items with Transaction ID {$data['transaction_id']}";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
                
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id, 'company_id' => $selected_principal);
				$updateCondition = array('from_company_id' => $company_id, 'company_id' => $selected_principal, 'original_id' => $original_id, 'transferred' => 0);

				if(!$temp_van_account_items_tbl->updateAll($updateSet, $updateCondition)) {
                    $result['message'][] = "Unable to Update Temp Van Account Items with Transaction ID {$data['transaction_id']}";
                    $result['hasError'] = true;
                }

			}

		}
		return $result;
    }

    public function saveVanAccountCollections( $vanAccountCollections, $company_id, $selected_principal, $transferred_id, $userAssignedWarehouse)
    {
        
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_collections_tbl = TableRegistry::get('temp_van_account_collections', ['connection' => $connection]);
        $van_account_collections_tbl = TableRegistry::get('VanAccountCollections', ['connection' => $connection]);

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');
       
        foreach ($vanAccountCollections as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_collections_tbl->find()->where($cond)->first();

			if($doExist){

                $van_account_collection = $van_account_collections_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $van_account_collection->$key = $selected_principal;
                        break;
                        default:
                            $van_account_collection->$key = $value;
                    }
                }

                $doInsert = $van_account_collections_tbl->save( $van_account_collection );
               
				if(!$doInsert) { 
                    $result['message'][] = "Van Account Collections cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_van_account_collections_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Van Account Collections cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveVarCollections( $varAccountCollections, $company_id, $selected_principal ) 
    {  
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_var_account_collections_tbl = TableRegistry::get('temp_var_collections', ['connection' => $connection]);
        $var_account_collections_tbl = TableRegistry::get('VarCollections', ['connection' => $connection]);

        foreach( $varAccountCollections as $key => $item ) {

			$data = json_decode(json_encode($item), true);
            $original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal,
                'transferred' => 0
            ];

			$doCollectionsExists = $temp_var_account_collections_tbl->find()->where( $cond )->first();
			
			if($doCollectionsExists){

				$var_account_collections = $var_account_collections_tbl->newEntity();
                
                unset($data['id']);
				foreach ($data as $key => $value) {

					switch ($key) {

						case 'account_code':
							$var_account_collections->$key = $doCollectionsExists['counterpart_account_code'];
                        break;

						case 'account_name':
                            $account_name = $this->getAccountNameByAccountCode( $doCollectionsExists['counterpart_account_code'], $selected_principal );
							$var_account_collections->$key = $account_name;
                        break;

						case 'company_id':
							$var_account_collections->$key = $selected_principal;
                        break;
						
						default:
                            $var_account_collections->$key = $value;
					}

				}

                $doInsert = $var_account_collections_tbl->save($var_account_collections);

                if( !$doInsert ) {
                    $result['message'][] = "Unable to Transfer Var Collections with Unique ID {$data['unique_id']}";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
                
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id, 'company_id' => $selected_principal);
				$updateCondition = array('from_company_id' => $company_id, 'company_id' => $selected_principal, 'original_id' => $original_id, 'transferred' => 0);

				if(!$temp_var_account_collections_tbl->updateAll($updateSet, $updateCondition)) {
                    $result['message'][] = "Unable to Update Temp Var Collections with Unique ID {$data['unique_id']}";
                    $result['hasError'] = true;
                }

			}
        }

        return $result;

    }

    public function saveAfterEditedVanAccountItems( $afterEditedVanAccountItems, $company_id, $selected_principal )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_after_edited_van_account_items_tbl = TableRegistry::get('temp_after_edited_van_account_items', ['connection' => $connection]);
        $after_edited_van_account_items_tbl = TableRegistry::get('afterEditedVanAccountItems', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($afterEditedVanAccountItems as $itemKey => $item) {

            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_after_edited_van_account_items_tbl->find()->where($cond)->first();
           
			if($doExist){

                $after_edited_van_account_items = $after_edited_van_account_items_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $after_edited_van_account_items->$key = $selected_principal;
                        break;
                        case 'product_item_code':
                            $after_edited_van_account_items->$key = $doExist['counterpart_product_code'];
                        break;
                        case 'uom':
                            $after_edited_van_account_items->$key = $doExist['counterpart_product_uom'];
                        break;
                        default:
                            $after_edited_van_account_items->$key = $value;
                    }
                }

                $doInsert = $after_edited_van_account_items_tbl->save( $after_edited_van_account_items );
               
				if(!$doInsert) { 
                    $result['message'][] = "After Edited Van Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_after_edited_van_account_items_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp After Edited Van Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveCancelledVanAccountCollections( $cancelledVanAccountCollections, $company_id, $selected_principal )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_cancelled_van_account_collections_tbl = TableRegistry::get('temp_cancelled_van_account_collections', ['connection' => $connection]);
        $cancelled_van_account_collections_tbl = TableRegistry::get('CancelledVanAccountCollections', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($cancelledVanAccountCollections as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_cancelled_van_account_collections_tbl->find()->where($cond)->first();

			if($doExist){

                $cancelled_van_account_collections = $cancelled_van_account_collections_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $cancelled_van_account_collections->$key = $selected_principal;
                        break;
                        default:
                            $cancelled_van_account_collections->$key = $value;
                    }
                }

                $doInsert = $cancelled_van_account_collections_tbl->save( $cancelled_van_account_collections );
               
				if(!$doInsert) { 
                    $result['message'][] = "Cancelled Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_cancelled_van_account_collections_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Cancelled Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveCancelledVanAccountTransactions( $cancelledVanAccountTransactions, $company_id, $selected_principal )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_cancelled_van_account_transactions_tbl = TableRegistry::get('temp_cancelled_van_account_transactions', ['connection' => $connection]);
        $cancelled_van_account_transactions_tbl = TableRegistry::get('CancelledVanAccountTransactions', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');
        
        foreach ($cancelledVanAccountCollections as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_cancelled_van_account_transactions_tbl->find()->where($cond)->first();

			if($doExist){

                $cancelled_van_account_transactions = $cancelled_van_account_transactions_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'username':
                            $username = $this->getCounterpartCodes( $company_id, $selected_principal, $item['username'], 10);
                            $cancelled_van_account_transactions->$key = $username;
                        break;
                        case 'warehouse_code':
                            $cancelled_van_account_transactions->$key = $doExist['counterpart_warehouse_code'];
                        break;
                        case 'account_code':
                            $cancelled_van_account_transactions->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'account_name':
                            $account_name = $this->getAccountNameByAccountCode( $doExist['counterpart_account_code'], $selected_principal );
                            $cancelled_van_account_transactions->$key = $account_name;
                        break;
                        case 'branch_code':
                            $cancelled_van_account_transactions->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'branch_name':
                            $branch_name = $this->getBranchNameByBranchCode( $doExist['counterpart_branch_code'], $selected_principal );
                            $cancelled_van_account_transactions->$key = $branch_name;
                        break;
                        case 'company_id':
                            $cancelled_van_account_transactions->$key = $selected_principal;
                        break;
                        default:
                            $cancelled_van_account_transactions->$key = $value;
                        break;
                    }
                }

                $doInsert = $cancelled_van_account_transactions_tbl->save( $cancelled_van_account_transactions );
               
				if(!$doInsert) { 
                    $result['message'][] = "Cancelled Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_cancelled_van_account_transactions_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Cancelled Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveCancelledVanAccountTransactionItems( $cancelledVanAccountTransactionItems, $company_id, $selected_principal )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_cancelled_van_account_transaction_items_tbl = TableRegistry::get('temp_cancelled_van_account_transaction_items', ['connection' => $connection]);
        $cancelled_van_account_transaction_items_tbl = TableRegistry::get('CancelledVanAccountTransactionItems', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($cancelledVanAccountTransactionItems as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_cancelled_van_account_transaction_items_tbl->find()->where($cond)->first();

			if($doExist){

                $cancelled_van_account_transactions_items = $cancelled_van_account_transaction_items_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $cancelled_van_account_transactions_items->$key = $selected_principal;
                        break;
                        case 'uom':
                            $cancelled_van_account_transactions_items->$key = $doExist['counterpart_product_uom'];
                        break;
                        case 'product_item_code':
                            $cancelled_van_account_transactions_items->$key = $doExist['counterpart_product_code'];
                        break;
                        default:
                            $cancelled_van_account_transactions_items->$key = $value;
                    }
                }

                $doInsert = $cancelled_van_account_transaction_items_tbl->save( $cancelled_van_account_transactions_items );
               
				if(!$doInsert) { 
                    $result['message'][] = "Cancelled Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_cancelled_van_account_transaction_items_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Cancelled Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveAccountNotOrderedItem( $vanAccountNotOrderedItems, $company_id, $selected_principal, $transferred_id, $data )
    {
        $temp_van_account_not_ordered_items_tbl = $data['temp_van_account_not_ordered_items_tbl'];

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');
        
        $to_return = [];
        $van_account_not_ordered_items_array = [];
        $original_ids = [];

        foreach ($vanAccountNotOrderedItems as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_not_ordered_items_tbl->find()->where($cond)->first();
          
			if($doExist){

                // $van_account_not_ordered_items = $van_account_not_ordered_items_tbl->newEntity(); 

                // unset($data['id']);
                // foreach( $data as $key => $value ) {

                //     switch( $key ) {
                //         case 'company_id':
                //             $van_account_not_ordered_items->$key = $selected_principal;
                //         break;
                //         case 'uom':
                //             $van_account_not_ordered_items->$key = $doExist['counterpart_product_uom'];
                //         break;
                //         case 'product_item_code':
                //             $van_account_not_ordered_items->$key = $doExist['counterpart_product_code'];
                //         break;
                //         default:
                //             $van_account_not_ordered_items->$key = $value;
                //     }
                // }

                // $doInsert = $van_account_not_ordered_items_tbl->save( $van_account_not_ordered_items );
               
				// if(!$doInsert) { 
                //     $result['message'][] = "Van Account Not Ordered Items cannot be transfered.";
                //     $result['hasError'] = true;
                // }

                // $transferred_id = $doInsert['id'];
				// $updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				// $updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				// if(!$temp_van_account_not_ordered_items_tbl->updateAll($updateSet, $updateCondition)) { 
                //     $result['message'][] = "Temp Van Account Not Ordered Items cannot be Updated.";
                //     $result['hasError'] = true;
                // }

                
                $temp_an_account_not_ordered_items = [];

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $temp_an_account_not_ordered_items[$key] = $selected_principal;
                        break;
                        case 'uom':
                            $temp_an_account_not_ordered_items[$key] = $doExist['counterpart_product_uom'];
                        break;
                        case 'product_item_code':
                            $temp_an_account_not_ordered_items[$key] = $doExist['counterpart_product_code'];
                        break;
                        case 'created':
                            $temp_an_account_not_ordered_items[$key] = date('Y-m-d H:i:s', strtotime($doExist['created']));
                        break;
                        case 'modified':
                            $temp_an_account_not_ordered_items[$key] = date('Y-m-d H:i:s', strtotime($doExist['modified']));
                        break;
                        default:
                            $temp_an_account_not_ordered_items[$key] = $value;
                    }
                }
                
                $temp_an_account_not_ordered_items['original_id'] = $original_id;

                $van_account_not_ordered_items_array[] = $temp_an_account_not_ordered_items;

			}

		}

		return $van_account_not_ordered_items_array;
    }

    public function saveEditedVanAccountTranscations( $editedVanAccountTransactions, $company_id, $selected_principal )
    {   
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_edited_van_account_transactions_tbl = TableRegistry::get('temp_edited_van_account_transactions', ['connection' => $connection]);
        $edited_van_account_transactions_tbl = TableRegistry::get('editedVanAccountTransactions', ['connection' => $connection]);
   
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($editedVanAccountTransactions as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_edited_van_account_transactions_tbl->find()->where($cond)->first();

			if($doExist){

                $edited_van_account_transactions = $edited_van_account_transactions_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'warehouse_code':
                            $edited_van_account_transactions->$key = $doExist['counterpart_warehouse_code'];
                        break;
                        case 'account_code':
                            $edited_van_account_transactions->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'account_name':
                            $account_name = $this->getAccountNameByAccountCode( $doExist['counterpart_account_code'], $selected_principal );
                            $edited_van_account_transactions->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'branch_code':
                            $edited_van_account_transactions->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'branch_name':
                            $branch_name = $this->getBranchNameByBranchCode( $doExist['counterpart_branch_code'], $selected_principal );
                            $edited_van_account_transactions->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'company_id':
                            $edited_van_account_transactions->$key = $selected_principal;
                        break;
                        default:
                            $edited_van_account_transactions->$key = $value;
                    }
                }

                $doInsert = $edited_van_account_transactions_tbl->save( $edited_van_account_transactions );
               
				if(!$doInsert) { 
                    $result['message'][] = "Edited Van Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_edited_van_account_transactions_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Edited Van Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }
    
    public function saveBeforeEditedVanAccountItems( $beforeEditedVanAccountItems, $company_id, $selected_principal )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_before_edited_van_account_items_tbl = TableRegistry::get('temp_before_edited_van_account_items', ['connection' => $connection]);
        $before_edited_van_account_items_tbl = TableRegistry::get('BeforeEditedVanAccountItems', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($beforeEditedVanAccountItems as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
    
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_before_edited_van_account_items_tbl->find()->where($cond)->first();
           
			if($doExist){

                $before_edited_van_account_items = $before_edited_van_account_items_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $before_edited_van_account_items->$key = $selected_principal;
                        break;
                        case 'product_item_code':
                            $before_edited_van_account_items->$key = $doExist['counterpart_product_code'];
                        break;
                        case 'uom':
                            $before_edited_van_account_items->$key = $doExist['counterpart_product_uom'];
                        break;
                        default:
                            $before_edited_van_account_items->$key = $value;
                    }
                }   
                
                $doInsert = $before_edited_van_account_items_tbl->save( $before_edited_van_account_items );
             
				if(!$doInsert) { 
                    $result['message'][] = "Before Edited Van Account Transactions cannot be transfered.";
                    $result['hasError'] = true;
                    return $result;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_before_edited_van_account_items_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Before Edited Van Account Transactions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }
    
    public function saveVanAccountTransactionDeduction( $vanAccountTransactionDeductions, $company_id, $selected_principal, $transferred_id )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_transaction_deductions_tbl = TableRegistry::get('temp_van_account_transaction_deductions', ['connection' => $connection]);
        $van_account_transaction_deductions_tbl = TableRegistry::get('vanAccountTransactionDeductions', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($vanAccountTransactionDeductions as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_transaction_deductions_tbl->find()->where($cond)->first();

			if($doExist){

                $van_account_transaction_deductions = $van_account_transaction_deductions_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $van_account_transaction_deductions->$key = $selected_principal;
                        break;
                        default:
                            $van_account_transaction_deductions->$key = $value;
                    }
                }

                $doInsert = $van_account_transaction_deductions_tbl->save( $van_account_transaction_deductions );
               
				if(!$doInsert) { 
                    $result['message'][] = "Van Account Transcation Deductions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_van_account_transaction_deductions_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Van Account Transcation Deductions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }
    
    public function saveVanAccountTranscationEditCodes( $vanAccountTransactionDeductions, $company_id, $selected_principal, $transferred_id  )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_transaction_deductions_tbl = TableRegistry::get('temp_van_account_transaction_deductions', ['connection' => $connection]);
        $van_account_transaction_deductions_tbl = TableRegistry::get('vanAccountTransactionDeductions', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($vanAccountTransactionDeductions as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_transaction_deductions_tbl->find()->where($cond)->first();

			if($doExist){

                $van_account_transaction_deductions = $van_account_transaction_deductions_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $van_account_transaction_deductions->$key = $selected_principal;
                        break;
                        default:
                            $van_account_transaction_deductions->$key = $value;
                    }
                }

                $doInsert = $van_account_transaction_deductions_tbl->save( $van_account_transaction_deductions );
               
				if(!$doInsert) { 
                    $result['message'][] = "Van Account Transcation Deductions cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_van_account_transaction_deductions_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Van Account Transcation Deductions cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function getUserAssignedToWarehouse($warehouseCode, $company_id, $username )
    {

        $connection = ConnectionManager::get('client_' . $company_id);
        $warehouses_tbl = TableRegistry::get('warehouses', ['connection' => $connection]);
        $user_assign_warehouse_tbl = TableRegistry::get('user_assign_warehouse', ['connection' => $connection]);

        $condition = [
            'warehouse_code' => $warehouseCode, 
            'username' => $username,
            'company_id' => $company_id
        ];

		$user = $user_assign_warehouse_tbl->find()->where($condition)->first();

        return isset($user) ? $user['username'] : false;
	}

    public function saveVanAccountServedProducts( $vanAccountServedProducts, $company_id, $selected_principal, $transferred_id )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_served_products_tbl = TableRegistry::get('temp_van_account_served_products', ['connection' => $connection]);
        $van_account_served_products_tbl = TableRegistry::get('vanAccountServedProducts', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($vanAccountServedProducts as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_served_products_tbl->find()->where($cond)->first();

			if($doExist){

                $van_account_served_products = $van_account_served_products_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'company_id':
                            $van_account_served_products->$key = $selected_principal;
                        break;
                        case 'product_item_code':
                            $van_account_served_products->$key = $doExist['counterpart_product_code'];
                        break;
                        case 'uom':
                            $van_account_served_products->$key = $doExist['counterpart_product_uom'];
                        break;
                        default:
                            $van_account_served_products->$key = $value;
                        break;
                    }
                }

                $doInsert = $van_account_served_products_tbl->save( $van_account_served_products );
               
				if(!$doInsert) { 
                    $result['message'][] = "Van Account Cancelled cannot be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_van_account_served_products_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Van Account Cancelled cannot be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }

    public function saveVanAccountTransactionEditRequests( $vanAccountTransactionEditRequests, $company_id, $selected_principal, $transferred_id )
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $temp_van_account_transaction_edit_requests_tbl = TableRegistry::get('temp_van_account_transaction_edit_requests', ['connection' => $connection]);
        $van_account_transaction_edit_requests_tbl = TableRegistry::get('vanAccountTransactionEditRequests', ['connection' => $connection]);
     
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        foreach ($vanAccountTransactionEditRequests as $itemKey => $item) {
            
            $data = json_decode(json_encode($item), true);
            
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'transferred' => 0
            ];

			$doExist = $temp_van_account_transaction_edit_requests_tbl->find()->where($cond)->first();

			if($doExist){

                $van_account_transaction_edit_requests = $van_account_transaction_edit_requests_tbl->newEntity(); 

                unset($data['id']);
                foreach( $data as $key => $value ) {

                    switch( $key ) {
                        case 'va_account_transaction_id':
                            $van_account_transaction_edit_requests->$key = $transferred_id;
                        break;
                        case 'account_code':
                            $van_account_transaction_edit_requests->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'account_name':
                            $account_name = $this->getAccountNameByAccountCode( $doExist['counterpart_account_code'], $selected_principal );
                            $van_account_transaction_edit_requests->$key = $doExist['counterpart_account_code'];
                        break;
                        case 'branch_code':
                            $van_account_transaction_edit_requests->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'branch_name':
                            $branch_name = $this->getBranchNameByBranchCode( $doExist['counterpart_branch_code'], $selected_principal );
                            $van_account_transaction_edit_requests->$key = $doExist['counterpart_branch_code'];
                        break;
                        case 'company_id':
                            $van_account_transaction_edit_requests->$key = $selected_principal;
                        break;
                        default:
                            $van_account_transaction_edit_requests->$key = $value;
                    }
                }

                $doInsert = $van_account_transaction_edit_requests_tbl->save( $van_account_transaction_edit_requests );
               
				if(!$doInsert) { 
                    $result['message'][] = "Van Account Transaction Edit Requests be transfered.";
                    $result['hasError'] = true;
                }

                $transferred_id = $doInsert['id'];
				$updateSet = array('transferred' => 1, 'transferred_date' => $date, 'transferred_id' => $transferred_id);
				$updateCondition = array('from_company_id' => $company_id, 'original_id' => $original_id, 'transferred' => 0);
                
				if(!$temp_van_account_transaction_edit_requests_tbl->updateAll($updateSet, $updateCondition)) { 
                    $result['message'][] = "Temp Van Account Transaction Edit Requests be Updated.";
                    $result['hasError'] = true;
                }


			}

		}

		return $result;
    }


    public function saveVaofToTemp( $transactions, $company_id, $selected_principal )
    {
        // $company_id = company_id , 
        $this->autoRender = false;
        $user = $this->Auth->user();
        $company_id = $user['company_id'];
        
        $default_connection = ConnectionManager::get('default');
        $principal_connection = ConnectionManager::get('client_' . $selected_principal);
        $users_table = TableRegistry::get('users', ['connection' => $default_connection]);
        $temp_van_account_collections_tbl = TableRegistry::get('temp_van_account_collections', ['connection' => $principal_connection]);
        $temp_van_account_items_tbl = TableRegistry::get('temp_van_account_items', ['connection' => $principal_connection]);
        $temp_var_collections_tbl = TableRegistry::get('temp_var_collections', ['connection' => $principal_connection]);
        $temp_after_edited_van_account_items_tbl = TableRegistry::get('temp_after_edited_van_account_items', ['connection' => $principal_connection]);
        $temp_before_edited_van_account_items_tbl = TableRegistry::get('temp_before_edited_van_account_items', ['connection' => $principal_connection]);
        $temp_cancelled_van_account_collections_tbl = TableRegistry::get('temp_cancelled_van_account_collections', ['connection' => $principal_connection]);
        $temp_cancelled_van_account_transactions_tbl = TableRegistry::get('temp_cancelled_van_account_transactions', ['connection' => $principal_connection]);
        $temp_cancelled_van_account_transaction_items_tbl = TableRegistry::get('temp_cancelled_van_account_transaction_items', ['connection' => $principal_connection]);
        $temp_edited_van_account_transactions_items_tbl = TableRegistry::get('temp_edited_van_account_transactions_items', ['connection' => $principal_connection]);
        $temp_edited_van_account_transactions_tbl = TableRegistry::get('temp_edited_van_account_transactions', ['connection' => $principal_connection]);
        $temp_van_account_not_ordered_items_tbl = TableRegistry::get('temp_van_account_not_ordered_items', ['connection' => $principal_connection]);
        $temp_van_account_served_products_tbl = TableRegistry::get('temp_van_account_served_products', ['connection' => $principal_connection]);
        $temp_van_account_transaction_deductions_tbl = TableRegistry::get('temp_van_account_transaction_deductions', ['connection' => $principal_connection]);
        $temp_van_account_transaction_edit_codes_tbl = TableRegistry::get('temp_van_account_transaction_edit_codes', ['connection' => $principal_connection]);
        $temp_van_account_transaction_edit_requests_tbl = TableRegistry::get('temp_van_account_transaction_edit_requests', ['connection' => $principal_connection]);
        $temp_van_account_transactions_tbl = TableRegistry::get('temp_van_account_transactions', ['connection' => $principal_connection]);
       

        $result = [];
        $result['message'] = [];
        $result['errorCount'] = 0;
        $date = date('Y-m-d H:i:s');

		$error = array();
		$errorMessage = array();
		$errorCount = 0;

		$errorAccount = array();
		$errorBranch = array();
		$errorWarehouse = array();
		$errorProduct = array();

        //start transaction
        $principal_connection->begin();
        
		foreach ($transactions as $key => $transaction) {
            
			$original_id = $transaction['id'];
			$transactionCompanyId = $transaction['company_id'];
			$warehouseCode = $transaction['warehouse_code'];
			$accountCode = $transaction['account_code'];
			$branchCode = $transaction['branch_code'];
			$warehouseCode = $transaction['warehouse_code'];
			$vanAccountTransactionId = $transaction['transaction_id'];

			$vanAccountCollections = $transaction['VanAccountCollections'];
			$vanAccountItems = $transaction['VanAccountItems'];
			$varCollections = $transaction['VarCollections'];
			$afterEditedVanAccountItems = $transaction['AfterEditedVanAccountItems'];
			$beforeEditedVanAccountItems = $transaction['BeforeEditedVanAccountItems'];
			$cancelledVanAccountCollections = $transaction['CancelledVanAccountCollections'];
			$cancelledVanAccountTransactions = $transaction['CancelledVanAccountTransactions'];
			$cancelledVanAccountTransactionItems = $transaction['CancelledVanAccountTransactionItems'];
			$editedVanAccountTransactions = $transaction['EditedVanAccountTransactions'];
			$vanAccountNotOrderedItems = $transaction['VanAccountNotOrderedItems'];
			$vanAccountServedProducts = $transaction['VanAccountServedProducts'];
			$vanAccountTransactionDeductions = $transaction['VanAccountTransactionDeductions'];
			$vanAccountTransactionEditCodes = $transaction['VanAccountTransactionEditCodes'];
			$vanAccountTransactionEditRequests = $transaction['VanAccountTransactionEditRequests'];
            
			$condition = array(
				'original_id' => $original_id,
				'from_company_id' => $company_id,
				'company_id' => $selected_principal
			);

			$doExist = $this->doExists( $condition, $temp_van_account_transactions_tbl );
			$transaction['company_id'] = $selected_principal;

			if(!$doExist){
        
                $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal, $accountCode, 1)['code'];
                $counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal, $branchCode, 2)['code'];
                $counterpartWarehouseCode = $this->getCounterpartCodes($company_id, $selected_principal, $warehouseCode, 3)['code'];
                
                if( $counterpartAccountCode == "" ) {
                    $result['message'][] = "CounterPartAccount Code For Account Code {$warehouseCode} is not Exists.";
                    $result['errorCount']++;
                    continue;
                }

                if( $counterpartBranchCode == "" ) {
                    $result['message'][] = "CounterPartAccount Code For Branch Code {$warehouseCode} is not Exists.";
                    $result['errorCount']++;
                    continue;
                }
                
                if( $counterpartWarehouseCode == "" ) {
                    $result['message'][] = "CounterPartAccount Code For Warehouse Code {$warehouseCode} is not Exists.";
                    $result['errorCount']++;
                    continue;
                }


                $user_id = $users_table->find()->where(['username' => $transaction['username']])->first()['id'];
                $temp_van_account_transaction = $temp_van_account_transactions_tbl->newEntity();
           
                $temp_van_account_transaction->visit_id = $transaction['visit_number'];
                $temp_van_account_transaction->user_id = $user_id;
                $temp_van_account_transaction->warehouse_code = $transaction['warehouse_code'];
                $temp_van_account_transaction->po_number = $transaction['po_number'];
                $temp_van_account_transaction->sales_invoice_number = $transaction['sales_invoice_number'];
                $temp_van_account_transaction->auto_invoice_number = $transaction['auto_invoice_number'];
                $temp_van_account_transaction->customer_name = $transaction['customer_name'];
                $temp_van_account_transaction->customer_email = $transaction['customer_email'];
                $temp_van_account_transaction->account_code = $transaction['account_code'];
                $temp_van_account_transaction->branch_code = $transaction['branch_code'];
                $temp_van_account_transaction->account_name = $transaction['account_name'];
                $temp_van_account_transaction->branch_name = $transaction['branch_name'];
                $temp_van_account_transaction->transaction_date = $transaction['transaction_date'];
                $temp_van_account_transaction->transaction_id = $transaction['transaction_id'];
                $temp_van_account_transaction->discount_value = $transaction['discount_value'];
                $temp_van_account_transaction->signature = $transaction['signature'];
                $temp_van_account_transaction->collected = $transaction['collected'];
                $temp_van_account_transaction->fully_paid = $transaction['fully_paid'];
                $temp_van_account_transaction->payment_type = $transaction['payment_type'];
                $temp_van_account_transaction->var_collection_unique_id = $transaction['var_collection_unique_id'];
                $temp_van_account_transaction->anti_fraud_message = $transaction['anti_fraud_message'];
                $temp_van_account_transaction->has_return_from_trade = $transaction['has_return_from_trade'];
                $temp_van_account_transaction->netsuite_transaction_id = $transaction['netsuite_transaction_id'];
                $temp_van_account_transaction->edit_count = $transaction['edit_count'];
                $temp_van_account_transaction->created = $date;
                $temp_van_account_transaction->modified = $date;
                
                $temp_van_account_transaction->original_id = $original_id;
                $temp_van_account_transaction->company_id = $selected_principal;
                $temp_van_account_transaction->from_company_id = $company_id;
                $temp_van_account_transaction->signature = 0;
                $temp_van_account_transaction->transferred = 0;
                $temp_van_account_transaction->transferred_id = 0;
                $temp_van_account_transaction->counterpart_account_code = $counterpartAccountCode;
                $temp_van_account_transaction->counterpart_branch_code = $counterpartBranchCode;
                $temp_van_account_transaction->counterpart_warehouse_code = $counterpartWarehouseCode;

				if(!$temp_van_account_transactions_tbl->save($temp_van_account_transaction)){ 
                    $result['message'][] = "Van Account Transaction with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                    $result['errorCount']++;
					continue ;
				}

			    $hasProductError = false;


                $tempVanAccountItems = $this->saveToTempVanAccountItems($temp_van_account_items_tbl,$vanAccountItems, $company_id, $selected_principal);
                
                if( count($tempVanAccountItems) > 0 ) {

                    $tempVanAccountItemEnteties = $temp_van_account_items_tbl->newEntities($tempVanAccountItems);
                 
                    if( ! $temp_van_account_items_tbl->saveMany($tempVanAccountItemEnteties)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Van Account Transaction Items with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }

                }


                $tempVanAccountCollections = $this->saveToTempVanAccountCollections($temp_van_account_collections_tbl, $vanAccountCollections, $company_id, $selected_principal);

                if( count($tempVanAccountCollections) > 0 ) {

                    $tempVanAccountCollectionsEntities = $temp_van_account_collections_tbl->newEntities($tempVanAccountCollections);

                    if( ! $temp_van_account_collections_tbl->saveMany($tempVanAccountCollectionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Van Account Collections with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }
                

                $tempVarCollections = $this->saveToTempVarCollections($temp_var_collections_tbl, $varCollections, $company_id, $selected_principal);

                if( count($tempVarCollections) > 0 ) {

                    $tempVarCollectionsEntities = $temp_var_collections_tbl->newEntities($tempVarCollections);

                    if( ! $temp_var_collections_tbl->saveMany($tempVarCollectionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Van Var Collections with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                // $tempAfterEditedVanAccountItems = $this->saveToTempAfterEditedVanAccountItems($temp_after_edited_van_account_items_tbl, $afterEditedVanAccountItems, $company_id, $selected_principal);
                
                // if( count($tempAfterEditedVanAccountItems) > 0 ) {

                //     $tempAfterEditedVanAccountItemsEntities = $temp_after_edited_van_account_items_tbl->newEntities($tempAfterEditedVanAccountItems);

                //     if( ! $temp_after_edited_van_account_items_tbl->saveMany($tempAfterEditedVanAccountItemsEntities)){
                        
                //         $principal_connection->rollback();
                //         $result['message'][] = "After Edited Van Account Items with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                //         $result['errorCount']++;
                //         continue ;
                //     }
                // }


                
                // $tempBeforeEditedVanAccountItems = $this->saveToTempBeforeEditedVanAccountItems($temp_before_edited_van_account_items_tbl, $beforeEditedVanAccountItems, $company_id, $selected_principal);

                // if( count($tempBeforeEditedVanAccountItems) > 0 ) {

                //     $tempBeforeEditedVanAccountItemsEntities = $temp_before_edited_van_account_items_tbl->newEntities($tempBeforeEditedVanAccountItems);

                //     if( ! $temp_before_edited_van_account_items_tbl->saveMany($tempBeforeEditedVanAccountItemsEntities)){
                        
                //         $principal_connection->rollback();
                //         $result['message'][] = "Before Edited Van Account Items with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                //         $result['errorCount']++;
                //         continue ;
                //     }
                // }


                $tempCancelledVanAccountCollections = $this->saveToTempCancelledVanAccountCollections($temp_cancelled_van_account_collections_tbl, $cancelledVanAccountCollections, $company_id, $selected_principal);

                if( count($tempCancelledVanAccountCollections) > 0 ) {

                    $tempCancelledVanAccountCollectionsEntities = $temp_cancelled_van_account_collections_tbl->newEntities($tempCancelledVanAccountCollections);

                    if( ! $temp_cancelled_van_account_collections_tbl->saveMany($tempCancelledVanAccountCollectionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Cancelled Van Account Collections with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }

                
                $tempCancelledVanAccountTransactions = $this->saveToTempCancelledVanAccountTransactions($temp_cancelled_van_account_transactions_tbl, $cancelledVanAccountTransactions, $company_id, $selected_principal);

                if( count($tempCancelledVanAccountTransactions) > 0 ) {

                    $tempCancelledVanAccountTransactionsEntities = $temp_cancelled_van_account_transactions_tbl->newEntities($tempCancelledVanAccountTransactions);

                    if( ! $temp_cancelled_van_account_transactions_tbl->saveMany($tempCancelledVanAccountTransactionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Cancelled Van Account Transactions with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempCancelledVanAccountTransactionItems = $this->saveToTempCancelledVanAccountTransactionItems($temp_cancelled_van_account_transaction_items_tbl, $cancelledVanAccountTransactionItems, $company_id, $selected_principal);

                if( count($tempCancelledVanAccountTransactionItems) > 0 ) {

                    $tempCancelledVanAccountTransactionItemsEntities = $temp_cancelled_van_account_transaction_items_tbl->newEntities($tempCancelledVanAccountTransactionItems);

                    if( ! $temp_cancelled_van_account_transaction_items_tbl->saveMany($tempCancelledVanAccountTransactionItemsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Cancelled Van Account Transactions Items with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempEditedVanAccountTransactions = $this->saveToTempEditedVanAccountTransactions($temp_edited_van_account_transactions_tbl, $editedVanAccountTransactions, $company_id, $selected_principal);

                if( count($tempEditedVanAccountTransactions) > 0 ) {

                    $tempEditedVanAccountTransactionsEntities = $temp_edited_van_account_transactions_tbl->newEntities($tempEditedVanAccountTransactions);

                    if( ! $temp_edited_van_account_transactions_tbl->saveMany($tempEditedVanAccountTransactionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Temp Edited Van Account Transactions with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempVanAccountServedProducts = $this->saveToTempVanAccountServedProducts($temp_van_account_served_products_tbl, $vanAccountServedProducts, $company_id, $selected_principal);
                if( count($tempVanAccountServedProducts) > 0 ) {

                    $tempVanAccountServedProductsEntities = $temp_van_account_served_products_tbl->newEntities($tempVanAccountServedProducts);

                    if( ! $temp_van_account_served_products_tbl->saveMany($tempVanAccountServedProductsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Temp Van Account Served Products with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempVanAccountTransactionDeductions = $this->saveToTempVanAccountTransactionDeductions($temp_van_account_transaction_deductions_tbl, $vanAccountTransactionDeductions, $company_id, $selected_principal);
                if( count($tempVanAccountTransactionDeductions) > 0 ) {

                    $tempVanAccountTransactionDeductionsEntities = $temp_van_account_transaction_deductions_tbl->newEntities($tempVanAccountTransactionDeductions);

                    if( ! $temp_van_account_transaction_deductions_tbl->saveMany($tempVanAccountTransactionDeductionsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Temp Van Account Served Products with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempVanAccountTransactionEditCodes = $this->saveToTempVanAccountTransactionEditCodes($temp_van_account_transaction_edit_codes_tbl, $vanAccountTransactionEditCodes, $company_id, $selected_principal);
                if( count($tempVanAccountTransactionEditCodes) > 0 ) {

                    $tempVanAccountTransactionEditCodesEntities = $temp_van_account_transaction_edit_codes_tbl->newEntities($tempVanAccountTransactionEditCodes);

                    if( ! $temp_van_account_transaction_edit_codes_tbl->saveMany($tempVanAccountTransactionEditCodesEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Temp Van Account transactions Edit Codes with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }


                $tempVanAccountTransactionEditRequests = $this->saveToTempVanAccountTransactionEditRequests($temp_van_account_transaction_edit_requests_tbl, $vanAccountTransactionEditRequests, $company_id, $selected_principal);
                if( count($tempVanAccountTransactionEditRequests) > 0 ) {

                    $tempVanAccountTransactionEditRequestsEntities = $temp_van_account_transaction_edit_requests_tbl->newEntities($tempVanAccountTransactionEditRequests);

                    if( ! $temp_van_account_transaction_edit_requests_tbl->saveMany($tempVanAccountTransactionEditRequestsEntities)){
                        
                        $principal_connection->rollback();
                        $result['message'][] = "Temp Van Account transactions Edit Requests with Transaction ID {$transaction['transaction_id']} cannot be save to temp.";
                        $result['errorCount']++;
                        continue ;
                    }
                }

            }

		}

        $principal_connection->commit();

		return $result;
    }

    public function getAllProductsCodeAndName($selected_principal)
    {
        $connection = ConnectionManager::get('client_' . $selected_principal);
        $products_tbl = TableRegistry::get('products', ['connection' => $connection]);

        $products  = $products_tbl->find()
                    ->where([
                        'company_id' => $comselected_principalpany_id,
    			        'deleted_status' => 0
                    ])
                    ->order(['name' => 'ASC'])
                    ->toArray();

    	return $products;
    }

    public function saveToTempVanAccountTransactionEditRequests($temp_van_account_transaction_edit_requests_tbl, $vanAccountTransactionEditRequests, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_transaction_edit_requests_arr = [];

		foreach ($vanAccountTransactionEditRequests as $itemKey => $item) {
			
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $temp_van_account_transaction_edit_requests_tbl->find()->where($cond)->first();
			
			$accountCode = $item['account_code'];
			$branchCode = $item['branch_code'];

			if(!$doExist){
                
                $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal, $accountCode, 1);
                $counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal, $branchCode, 2);
                
                //skip iteration
                if($counterpartAccountCode == "" || $counterpartBranchCode == ""){
                    continue;
                }


                $user_id = $this->getUserIdByUsername( $item['username'] );
                // $TempVanAccountTransactionEditRequest = $temp_van_account_transaction_edit_requests_tbl->newEntity();

                // $TempVanAccountTransactionEditRequest->request_by_user_id = $user_id;
                // $TempVanAccountTransactionEditRequest->request_by_user_name = $item['request_by_user_name'];
                // $TempVanAccountTransactionEditRequest->request_by_first_name = $item['request_by_first_name'];
                // $TempVanAccountTransactionEditRequest->request_by_last_name = $item['request_by_last_name'];
                // $TempVanAccountTransactionEditRequest->request_to_user_id = $item['request_to_user_id'];
                // $TempVanAccountTransactionEditRequest->request_to_user_name = $item['request_to_user_name'];
                // $TempVanAccountTransactionEditRequest->van_account_transaction_id = $item['van_account_transaction_id'];
                // $TempVanAccountTransactionEditRequest->customer_name = $item['customer_name'];
                // $TempVanAccountTransactionEditRequest->account_code = $item['account_code'];
                // $TempVanAccountTransactionEditRequest->account_name = $item['account_name'];
                // $TempVanAccountTransactionEditRequest->branch_code = $item['branch_code'];
                // $TempVanAccountTransactionEditRequest->branch_name = $item['branch_name'];
                // $TempVanAccountTransactionEditRequest->transaction_date = $item['transaction_date'];
                // $TempVanAccountTransactionEditRequest->po_number = $item['po_number'];
                // $TempVanAccountTransactionEditRequest->sales_invoice_number = $item['sales_invoice_number'];
                // $TempVanAccountTransactionEditRequest->auto_invoice_number = $item['auto_invoice_number'];
                // $TempVanAccountTransactionEditRequest->grand_total_with_tax = $item['grand_total_with_tax'];
                // $TempVanAccountTransactionEditRequest->approve = $item['approve'];
                // $TempVanAccountTransactionEditRequest->created = $date;
                // $TempVanAccountTransactionEditRequest->modified = $date;
				
				
                // $TempVanAccountTransactionEditRequest->original_id = $original_id;
                // $TempVanAccountTransactionEditRequest->company_id = $selected_principal;
                // $TempVanAccountTransactionEditRequest->from_company_id = $company_id;
                // $TempVanAccountTransactionEditRequest->transferred = 0;
                // $TempVanAccountTransactionEditRequest->transferred_id = 0;
                // $TempVanAccountTransactionEditRequest->counterpart_account_code = $counterpartAccountCode['code'];
                // $TempVanAccountTransactionEditRequest->counterpart_branch_code = $counterpartAccountCode['code'];
                

				// if( !$temp_van_account_transaction_edit_requests_tbl->save($TempVanAccountTransactionEditRequest) ){ 
                //     $result['message'][] = "Unable to save Van Account Transaction Edit Request";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['request_by_user_id'] = $user_id;
                $temp['request_by_user_name'] = $item['request_by_user_name'];
                $temp['request_by_first_name'] = $item['request_by_first_name'];
                $temp['request_by_last_name'] = $item['request_by_last_name'];
                $temp['request_to_user_id'] = $item['request_to_user_id'];
                $temp['request_to_user_name'] = $item['request_to_user_name'];
                $temp['van_account_transaction_id'] = $item['van_account_transaction_id'];
                $temp['customer_name'] = $item['customer_name'];
                $temp['account_code'] = $item['account_code'];
                $temp['account_name'] = $item['account_name'];
                $temp['branch_code'] = $item['branch_code'];
                $temp['branch_name'] = $item['branch_name'];
                $temp['transaction_date'] = $item['transaction_date'];
                $temp['po_number'] = $item['po_number'];
                $temp['sales_invoice_number'] = $item['sales_invoice_number'];
                $temp['auto_invoice_number'] = $item['auto_invoice_number'];
                $temp['grand_total_with_tax'] = $item['grand_total_with_tax'];
                $temp['approve'] = $item['approve'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
				
				
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_account_code'] = $counterpartAccountCode['code'];
                $temp['counterpart_branch_code'] = $counterpartAccountCode['code'];
             
                $temp_van_account_transaction_edit_requests_arr = [];
			}

		}

		return $temp_van_account_transaction_edit_requests_arr;
    }

    public function saveToTempVanAccountTransactionEditCodes($temp_van_account_transaction_edit_codes_tbl, $vanAccountTransactionEditCodes, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_transaction_edit_codes_arr = [];

		foreach ($vanAccountTransactionEditCodes as $itemKey => $item) {
	
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $temp_van_account_transaction_edit_codes_tbl->find()->where($cond)->first();

			if(!$doExist){

				// $TempVanAccountTransactionEditCode = $temp_van_account_transaction_edit_codes_tbl->newEntity();
                
                // $TempVanAccountTransactionEditCode->van_account_transaction_edit_requests_id = $item['van_account_transaction_edit_requests_id'];
                // $TempVanAccountTransactionEditCode->van_account_transaction_id = $item['van_account_transaction_id'];
                // $TempVanAccountTransactionEditCode->van_account_transaction_po_number = $item['van_account_transaction_po_number'];
                // $TempVanAccountTransactionEditCode->van_account_transaction_sales_invoice_number = $item['van_account_transaction_sales_invoice_number'];
                // $TempVanAccountTransactionEditCode->request_code = $item['request_code'];
                // $TempVanAccountTransactionEditCode->date_requested = $item['date_requested'];
                // $TempVanAccountTransactionEditCode->used = $item['used'];
                // $TempVanAccountTransactionEditCode->requested_by_user_id = $this->getUserIdByUsername( $item['requested_by_username'] );
                // $TempVanAccountTransactionEditCode->created = $date;
                // $TempVanAccountTransactionEditCode->modified = $date;
                
                // $TempVanAccountTransactionEditCode->original_id = $original_id;
                // $TempVanAccountTransactionEditCode->company_id = $selected_principal;
                // $TempVanAccountTransactionEditCode->from_company_id = $company_id;
                // $TempVanAccountTransactionEditCode->transferred = 0;
                // $TempVanAccountTransactionEditCode->transferred_id = 0;
                
				// if(!$temp_van_account_transaction_edit_codes_tbl->save($TempVanAccountTransactionEditCode)){ 
                //     $result['message'][] = "Unable to Save Temp Van Account Edit Codes";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['van_account_transaction_edit_requests_id'] = $item['van_account_transaction_edit_requests_id'];
                $temp['van_account_transaction_id'] = $item['van_account_transaction_id'];
                $temp['van_account_transaction_po_number'] = $item['van_account_transaction_po_number'];
                $temp['van_account_transaction_sales_invoice_number'] = $item['van_account_transaction_sales_invoice_number'];
                $temp['request_code'] = $item['request_code'];
                $temp['date_requested'] = $item['date_requested'];
                $temp['used'] = $item['used'];
                $temp['requested_by_user_id'] = $this->getUserIdByUsername( $item['requested_by_username'] );
                $temp['created'] = $date;
                $temp['modified'] = $date;
                
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;


                $temp_van_account_transaction_edit_codes_arr[] = $temp;
			}

		}

		return $temp_van_account_transaction_edit_codes_arr;
    }

    public function saveToTempVanAccountTransactionDeductions($temp_van_account_transaction_deductions_tbl, $vanAccountTransactionDeductions, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_transaction_deductions_arr = [];

		foreach ($vanAccountTransactionDeductions as $itemKey => $item) {
			
			$original_id = $item['id'];

            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $temp_van_account_transaction_deductions_tbl->find()->where($cond)->first();

			if(!$doExist){

                // $TempVanAccountTransactionDeduction = $temp_van_account_transaction_deductions_tbl->newEntity();
                
                // $TempVanAccountTransactionDeduction->van_account_transaction_id = $item['van_account_transaction_id'];
                // $TempVanAccountTransactionDeduction->van_account_transaction_transaction_id = $item['van_account_transaction_transaction_id'];
                // $TempVanAccountTransactionDeduction->van_account_transaction_po_number = $item['van_account_transaction_po_number'];
                // $TempVanAccountTransactionDeduction->var_collection_unique_id = $item['var_collection_unique_id'];
                // $TempVanAccountTransactionDeduction->var_official_receipt_number = $item['var_official_receipt_number'];
                // $TempVanAccountTransactionDeduction->deduction_id = $item['deduction_id'];
                // $TempVanAccountTransactionDeduction->deduction_type = $item['deduction_type'];
                // $TempVanAccountTransactionDeduction->document_name = $item['document_name'];
                // $TempVanAccountTransactionDeduction->document_number = $item['document_number'];
                // $TempVanAccountTransactionDeduction->value_applied = $item['value_applied'];
                // $TempVanAccountTransactionDeduction->document_date = $item['document_date'];
                // $TempVanAccountTransactionDeduction->date_created = $item['date_created'];
                // $TempVanAccountTransactionDeduction->created = $date;
                // $TempVanAccountTransactionDeduction->modified = $date;
				
                // $TempVanAccountTransactionDeduction->original_id = $original_id;
                // $TempVanAccountTransactionDeduction->company_id = $selected_principal;
                // $TempVanAccountTransactionDeduction->from_company_id = $company_id;
                // $TempVanAccountTransactionDeduction->transferred = 0;
                // $TempVanAccountTransactionDeduction->transferred_id = 0;

				// if(!$temp_van_account_transaction_deductions_tbl->save($TempVanAccountTransactionDeduction)){
                //     $result['message'][] = "Unable to Save Temp Van Account Transaction Deduction";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['van_account_transaction_id'] = $item['van_account_transaction_id'];
                $temp['van_account_transaction_transaction_id'] = $item['van_account_transaction_transaction_id'];
                $temp['van_account_transaction_po_number'] = $item['van_account_transaction_po_number'];
                $temp['var_collection_unique_id'] = $item['var_collection_unique_id'];
                $temp['var_official_receipt_number'] = $item['var_official_receipt_number'];
                $temp['deduction_id'] = $item['deduction_id'];
                $temp['deduction_type'] = $item['deduction_type'];
                $temp['document_name'] = $item['document_name'];
                $temp['document_number'] = $item['document_number'];
                $temp['value_applied'] = $item['value_applied'];
                $temp['document_date'] = $item['document_date'];
                $temp['date_created'] = $item['date_created'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
				
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;

                $temp_van_account_transaction_deductions_arr[] = $temp;
			}

		}

		return $temp_van_account_transaction_deductions_arr;

    }

    public function saveToTempVanAccountServedProducts($temp_van_account_served_products_tbl, $vanAccountServedProducts, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_served_products_arr = [];

		foreach ($vanAccountServedProducts as $itemKey => $item) {
	
			$original_id = $item['id'];

			$cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $temp_van_account_served_products_tbl->find()->where($cond)->first();
			
			$productCode = $item['product_code'];
            $productUom = $item['uom'];

			if( !$doExist ){

                $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);
                $counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);

                //skip iteration
                if($counterpartProductCode == ""){
                    
                    /*array_push($errorProduct, $productCode);
                    $hasError = true;*/
                    continue;
                }

                // $TempVanAccountServedProduct = $temp_van_account_served_products_tbl->newEntity();

				
                // $TempVanAccountServedProduct->van_account_transaction_id = $item['van_account_transaction_id'];
                // $TempVanAccountServedProduct->product_item_code = $item['product_code'];
                // $TempVanAccountServedProduct->uom = $item['uom'];
                // $TempVanAccountServedProduct->quantity = $item['quantity'];
                // $TempVanAccountServedProduct->created = $date;
                // $TempVanAccountServedProduct->modified = $date;
                
                // $TempVanAccountServedProduct->original_id = $original_id;
                // $TempVanAccountServedProduct->company_id = $selected_principal;
                // $TempVanAccountServedProduct->from_company_id = $company_id;
                // $TempVanAccountServedProduct->transferred = 0;
                // $TempVanAccountServedProduct->transferred_id = 0;
                // $TempVanAccountServedProduct->counterpart_product_code = $counterpartProductCode;
                // $TempVanAccountServedProduct->counterpart_product_uom = $counterpartProductUom;

				// if(!$temp_van_account_served_products_tbl->save($TempVanAccountServedProduct)){ 
                //     $result['message'][] = "Unable to save temp van account serverd products";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                
                $temp['van_account_transaction_id'] = $item['van_account_transaction_id'];
                $temp['product_item_code'] = $item['product_code'];
                $temp['uom'] = $item['uom'];
                $temp['quantity'] = $item['quantity'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
                
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_product_code'] = $counterpartProductCode;
                $temp['counterpart_product_uom'] = $counterpartProductUom;

                $temp_van_account_served_products_arr[] = $temp;
			}

		}

		return $temp_van_account_served_products_arr;
    }
    
    public function saveToTempVanAccountNotOrderedItems( $temp_van_account_not_ordered_items_tbl, $vanAccountNotOrderedItems, $company_id, $selected_principal )
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_not_ordered_items_arr = [];

		foreach ($vanAccountNotOrderedItems as $itemKey => $item) {
	
			$original_id = $item['id'];
            
            $cond = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
            ];

            $doExist = $temp_van_account_not_ordered_items_tbl->find()->where($cond)->first();
            
			
			$productCode = $item['product_item_code'];
            $productUom = $item['uom'];

			if(!$doExist){

                $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);
                $counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);

                //skip iteration
                if($counterpartProductCode == ""){
                    
                    /*array_push($errorProduct, $productCode); 
                    $hasError = true;*/
                    continue;
                }

                // $TempVanAccountNotOrderedItem = $temp_van_account_not_ordered_items_tbl->newEntity();
                
                // $TempVanAccountNotOrderedItem->van_account_transaction_online_id = $item['van_account_transaction_online_id'];
                // $TempVanAccountNotOrderedItem->product_item_code = $item['product_item_code'];
                // $TempVanAccountNotOrderedItem->uom = $item['uom'];
                // $TempVanAccountNotOrderedItem->inventory_in_smallest_uom = $item['inventory_in_smallest_uom'];
                // $TempVanAccountNotOrderedItem->van_account_transaction_id = $item['van_account_transaction_id'];
                // $TempVanAccountNotOrderedItem->auto_invoice_number = $item['auto_invoice_number'];
                // $TempVanAccountNotOrderedItem->created = $date;
                // $TempVanAccountNotOrderedItem->modified = $date;

                // $TempVanAccountNotOrderedItem->original_id = $original_id;
                // $TempVanAccountNotOrderedItem->company_id = $selected_principal;
                // $TempVanAccountNotOrderedItem->from_company_id = $company_id;
                // $TempVanAccountNotOrderedItem->transferred = 0;
                // $TempVanAccountNotOrderedItem->transferred_id = 0;
                // $TempVanAccountNotOrderedItem->counterpart_product_code = $counterpartProductCode;
                // $TempVanAccountNotOrderedItem->counterpart_product_uom = $counterpartProductUom;

                // $doInsert = $temp_van_account_not_ordered_items_tbl->save($TempVanAccountNotOrderedItem);


				// if(!$doInsert){ 
                //     $result['message'][] ="Unable to Save temp van account unordered items ";
                //     $result['hasError'] = true;
				// 	continue;
				// }


                $temp = [];

                $temp['van_account_transaction_online_id'] = $item['van_account_transaction_online_id'];
                $temp['product_item_code'] = $item['product_item_code'];
                $temp['uom'] = $item['uom'];
                $temp['inventory_in_smallest_uom'] = $item['inventory_in_smallest_uom'];
                $temp['van_account_transaction_id'] = $item['van_account_transaction_id'];
                $temp['auto_invoice_number'] = $item['auto_invoice_number'];
                $temp['created'] = $date;
                $temp['modified'] = $date;

                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_product_code'] = $counterpartProductCode;
                $temp['counterpart_product_uom'] = $counterpartProductUom;


                $temp_van_account_not_ordered_items_arr[] = $temp;

			}

		}

		return $temp_van_account_not_ordered_items_arr;
    }

    public function saveToTempEditedVanAccountTransactions($temp_edited_van_account_transactions_tbl, $editedVanAccountTransactions, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError']= false;
        $date = date('Y-m-d H:i:s');

        $temp_edited_van_account_transactions_arr = [];

		foreach ($editedVanAccountTransactions as $itemKey => $item) {
	
			$original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

            $doExist = $this->doExists( $where, $temp_edited_van_account_transactions_tbl );

			$accountCode = $item['account_code'];
			$branchCode = $item['branch_code'];
			$warehouseCode = $item['warehouse_code'];

			if(!$doExist){

                $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal, $accountCode, 1);
                $counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal, $branchCode, 2);
                $counterpartWarehouseCode = $this->getCounterpartCodes($company_id, $selected_principal, $warehouseCode, 3);

                //skip iteration
                if( $counterpartWarehouseCode == "" ){
                    $result['message'][] = "unable to save temp edited van account transcations because it has no counterpart warehouse code for  {$warehouseCode}";
                    $result['hasError'] = true;
                    continue;
                }
                if( $counterpartAccountCode == ""  ){
                    $result['message'][] = "unable to save temp edited van account transcations because it has no counterpart account code for  {$accountCode}";
                    $result['hasError'] = true;
                    continue;
                }
                if( $counterpartBranchCode == ""  ){
                    $result['message'][] = "unable to save temp edited van account transcations because it has no counterpart branch code for  {$branchCode}";
                    $result['hasError'] = true;
                    continue;
                }
            

                // $TempEditedVanAccountTransaction = $temp_edited_van_account_transactions_tbl->newEntity();
                
                // $TempEditedVanAccountTransaction->warehouse_code = $item['warehouse_code'];
                // $TempEditedVanAccountTransaction->po_number = $item['po_number'];
                // $TempEditedVanAccountTransaction->customer_name = $item['customer_name'];
                // $TempEditedVanAccountTransaction->customer_email = $item['customer_email'];
                // $TempEditedVanAccountTransaction->account_code = $item['account_code'];
                // $TempEditedVanAccountTransaction->branch_code = $item['branch_code'];
                // $TempEditedVanAccountTransaction->account_name = $item['account_name'];
                // $TempEditedVanAccountTransaction->branch_name = $item['branch_name'];
                // $TempEditedVanAccountTransaction->transaction_date = $item['transaction_date'];
                // $TempEditedVanAccountTransaction->transaction_id = $item['transaction_id'];
                // $TempEditedVanAccountTransaction->auto_generated_invoice = $item['auto_generated_invoice'];
                // $TempEditedVanAccountTransaction->edit_datetime = $item['edit_datetime'];
                // $TempEditedVanAccountTransaction->visit_id = $item['visit_id'];
                // $TempEditedVanAccountTransaction->is_edited_via_ems = $item['is_edited_via_ems'];
                // $TempEditedVanAccountTransaction->editor_name = $item['editor_name'];
                // $TempEditedVanAccountTransaction->note = $item['note'];
                // $TempEditedVanAccountTransaction->created = $date;
                // $TempEditedVanAccountTransaction->modified = $date;
                
                // $TempEditedVanAccountTransaction->original_id = $original_id;
                // $TempEditedVanAccountTransaction->company_id = $selected_principal;
                // $TempEditedVanAccountTransaction->from_company_id = $company_id;
                // $TempEditedVanAccountTransaction->transferred = 0;
                // $TempEditedVanAccountTransaction->transferred_id = 0;
                // $TempEditedVanAccountTransaction->counterpart_account_code = $counterpartAccountCode['code'];
                // $TempEditedVanAccountTransaction->counterpart_branch_code = $counterpartBranchCode['code'];
                // $TempEditedVanAccountTransaction->counterpart_warehouse_code = $counterpartWarehouseCode['code'];

				// $doInserted = $temp_edited_van_account_transactions_tbl->save($TempEditedVanAccountTransaction);

				// if(!$doInserted){ 
                //     $result['message'][] = "unable to save temp edited van account transcations with transaction ID  {$item['transaction_id']}";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];
                    
                $temp['warehouse_code'] = $item['warehouse_code'];
                $temp['po_number'] = $item['po_number'];
                $temp['customer_name'] = $item['customer_name'];
                $temp['customer_email'] = $item['customer_email'];
                $temp['account_code'] = $item['account_code'];
                $temp['branch_code'] = $item['branch_code'];
                $temp['account_name'] = $item['account_name'];
                $temp['branch_name'] = $item['branch_name'];
                $temp['transaction_date'] = $item['transaction_date'];
                $temp['transaction_id'] = $item['transaction_id'];
                $temp['auto_generated_invoice'] = $item['auto_generated_invoice'];
                $temp['edit_datetime'] = $item['edit_datetime'];
                $temp['visit_id'] = $item['visit_id'];
                $temp['is_edited_via_ems'] = $item['is_edited_via_ems'];
                $temp['editor_name'] = $item['editor_name'];
                $temp['note'] = $item['note'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
                
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_account_code'] = $counterpartAccountCode['code'];
                $temp['counterpart_branch_code'] = $counterpartBranchCode['code'];
                $temp['counterpart_warehouse_code'] = $counterpartWarehouseCode['code'];

                $temp_edited_van_account_transactions_arr[] = $temp;
			}

		}

		return $temp_edited_van_account_transactions_arr;
    }

    public function saveToTempCancelledVanAccountTransactionItems( $temp_cancelled_van_account_transaction_items_tbl, $cancelledVanAccountTransactionItems, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_cancelled_van_account_transaction_items_arr = [];

		foreach ($cancelledVanAccountTransactionItems as $itemKey => $item) {
			
			$original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

            $doExist = $this->doExists( $where, $temp_cancelled_van_account_transaction_items_tbl );

			if(!$doExist){
			
                $productCode = $item['product_item_code'];
                $productUom = $item['uom'];

                $counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);
                $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);
            

                // $TempCancelledVanAccountTransactionItem = $temp_cancelled_van_account_transaction_items_tbl->newEntity();
                
                
                // $TempCancelledVanAccountTransactionItem->cancelled_van_account_transaction_id = $item['cancelled_van_account_transaction_id'];
                // $TempCancelledVanAccountTransactionItem->van_account_transaction_transaction_id = $item['van_account_transaction_transaction_id'];
                // $TempCancelledVanAccountTransactionItem->product_item_code = $item['product_code'];
                // $TempCancelledVanAccountTransactionItem->uom = $item['uom'];
                // $TempCancelledVanAccountTransactionItem->quantity = $item['quantity'];
                // $TempCancelledVanAccountTransactionItem->stock_availability = $item['stock_availability'];
                // $TempCancelledVanAccountTransactionItem->stock_weight = $item['stock_weight'];
                // $TempCancelledVanAccountTransactionItem->price_with_tax = $item['price_with_tax'];
                // $TempCancelledVanAccountTransactionItem->price_without_tax = $item['price_without_tax'];
                // $TempCancelledVanAccountTransactionItem->created = $date;
                // $TempCancelledVanAccountTransactionItem->modified = $date;
				
                // $TempCancelledVanAccountTransactionItem->original_id = $original_id;
                // $TempCancelledVanAccountTransactionItem->company_id = $selected_principal;
                // $TempCancelledVanAccountTransactionItem->from_company_id = $company_id;
                // $TempCancelledVanAccountTransactionItem->transferred = 0;
                // $TempCancelledVanAccountTransactionItem->transferred_id = 0;
                // $TempCancelledVanAccountTransactionItem->counterpart_product_code = $counterpartProductCode;
                // $TempCancelledVanAccountTransactionItem->counterpart_product_uom = $counterpartProductUom;

                // $doInsert = $temp_cancelled_van_account_transaction_items_tbl->save($TempCancelledVanAccountTransactionItem);


				// if(!$doInsert){ 
                //     $result['message'][] = "Unable To Save Temp Van Account Transactions Item with Transaction ID {$item['van_account_transaction_transaction_id']}";
				// 	$result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['cancelled_van_account_transaction_id'] = $item['cancelled_van_account_transaction_id'];
                $temp['van_account_transaction_transaction_id'] = $item['van_account_transaction_transaction_id'];
                $temp['product_item_code'] = $item['product_code'];
                $temp['uom'] = $item['uom'];
                $temp['quantity'] = $item['quantity'];
                $temp['stock_availability'] = $item['stock_availability'];
                $temp['stock_weight'] = $item['stock_weight'];
                $temp['price_with_tax'] = $item['price_with_tax'];
                $temp['price_without_tax'] = $item['price_without_tax'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
				
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_product_code'] = $counterpartProductCode;
                $temp['counterpart_product_uom'] = $counterpartProductUom;

                $temp_cancelled_van_account_transaction_items_arr[] = $temp;

			}

		}

		return $temp_cancelled_van_account_transaction_items_arr;
    }

    public function saveToTempCancelledVanAccountTransactions($temp_cancelled_van_account_transactions_tbl, $cancelledVanAccountTransactions, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_cancelled_van_account_transactions_arr = [];

		foreach ($cancelledVanAccountTransactions as $itemKey => $item) {
			
            $original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

            $doExist = $this->doExists( $where, $temp_cancelled_van_account_transactions_tbl );
			
			$accountCode = $_item['account_code'];
			$branchCode = $_item['branch_code'];
			$warehouseCode = $_item['warehouse_code'];


			if(!$doExist){

                $counterpartAccountCode = $this->getCounterpartCodes($company_id, $selected_principal, $accountCode, 1);
                $counterpartBranchCode = $this->getCounterpartCodes($company_id, $selected_principal, $branchCode, 2);
                $counterpartWarehouseCode = $this->getCounterpartCodes($company_id, $selected_principal, $warehouseCode, 3);
    
                if( $counterpartWarehouseCode == "" ) {
                    $result['message'][] = "Unable to Save temp cancelled van account transactions because it has no counterpart account code for { $accountCode }";
                    $result['hasError'] = true;
                    continue;
                }
    
                if( $counterpartBranchCode == "" ) {
                    $result['message'][] = "Unable to Save temp cancelled van account transactions because it has no counterpart branch code for { $branchCode }";
                    $result['hasError'] = true;
                    continue;
                }
    
                if( $counterpartWarehouseCode == "" ) {
                    $result['message'][] = "Unable to Save temp cancelled van account transactions because it has no counterpart warehouse code for { $warehouseCode }";
                    $result['hasError'] = true;
                    continue;
                }

                $user_id = $this->getUserIdByUsername( $username );
                // $TempCancelledVanAccountTransaction = $temp_cancelled_van_account_transactions_tbl->newEntity();
                                
                // $TempCancelledVanAccountTransaction->visit_id = $item['visit_number'];
                // $TempCancelledVanAccountTransaction->user_id = $user_id;
                // $TempCancelledVanAccountTransaction->warehouse_code = $item['warehouse_code'];
                // $TempCancelledVanAccountTransaction->po_number = $item['po_number'];
                // $TempCancelledVanAccountTransaction->auto_invoice_number = $item['auto_invoice_number'];
                // $TempCancelledVanAccountTransaction->customer_name = $item['customer_name'];
                // $TempCancelledVanAccountTransaction->customer_email = $item['customer_email'];
                // $TempCancelledVanAccountTransaction->account_code = $item['account_code'];
                // $TempCancelledVanAccountTransaction->branch_code = $item['branch_code'];
                // $TempCancelledVanAccountTransaction->account_name = $item['account_name'];
                // $TempCancelledVanAccountTransaction->branch_name = $item['branch_name'];
                // $TempCancelledVanAccountTransaction->transaction_date = $item['transaction_date'];
                // $TempCancelledVanAccountTransaction->transaction_id = $item['transaction_id'];
                // $TempCancelledVanAccountTransaction->discount_value = $item['discount_value'];
                // $TempCancelledVanAccountTransaction->signature = $item['signature'];
                // $TempCancelledVanAccountTransaction->collected = $item['collected'];
                // $TempCancelledVanAccountTransaction->fully_paid = $item['fully_paid'];
                // $TempCancelledVanAccountTransaction->payment_type = $item['payment_type'];
                // $TempCancelledVanAccountTransaction->var_collection_unique_id = $item['var_collection_unique_id'];
                // $TempCancelledVanAccountTransaction->created = $date;
                // $TempCancelledVanAccountTransaction->modified = $date;

                // $TempCancelledVanAccountTransaction->original_id = $original_id;
                // $TempCancelledVanAccountTransaction->company_id = $selected_principal;
                // $TempCancelledVanAccountTransaction->from_company_id = $company_id;
                // $TempCancelledVanAccountTransaction->transferred = 0;
                // $TempCancelledVanAccountTransaction->transferred_id = 0;
                // $TempCancelledVanAccountTransaction->counterpart_account_code = $counterpartAccountCode['code'];
                // $TempCancelledVanAccountTransaction->counterpart_branch_code = $counterpartBranchCode['code'];
                // $TempCancelledVanAccountTransaction->counterpart_warehouse_code = $counterpartWarehouseCode['code'];

                // $doInsert = $temp_cancelled_van_account_transactions_tbl->save($TempCancelledVanAccountTransaction);

				// if(!$doInsert){ 
                //     $result['message'][] = "Unable to Save temp cancelled van account transactions with transacation ID {$item['transaction_id']}";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];
                
                $temp['visit_id'] = $item['visit_number'];
                $temp['user_id'] = $user_id;
                $temp['warehouse_code'] = $item['warehouse_code'];
                $temp['po_number'] = $item['po_number'];
                $temp['auto_invoice_number'] = $item['auto_invoice_number'];
                $temp['customer_name'] = $item['customer_name'];
                $temp['customer_email'] = $item['customer_email'];
                $temp['account_code'] = $item['account_code'];
                $temp['branch_code'] = $item['branch_code'];
                $temp['account_name'] = $item['account_name'];
                $temp['branch_name'] = $item['branch_name'];
                $temp['transaction_date'] = $item['transaction_date'];
                $temp['transaction_id'] = $item['transaction_id'];
                $temp['discount_value'] = $item['discount_value'];
                $temp['signature'] = $item['signature'];
                $temp['collected'] = $item['collected'];
                $temp['fully_paid'] = $item['fully_paid'];
                $temp['payment_type'] = $item['payment_type'];
                $temp['var_collection_unique_id'] = $item['var_collection_unique_id'];
                $temp['created'] = $date;
                $temp['modified'] = $date;

                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_account_code'] = $counterpartAccountCode['code'];
                $temp['counterpart_branch_code'] = $counterpartBranchCode['code'];
                $temp['counterpart_warehouse_code'] = $counterpartWarehouseCode['code'];

                $temp_cancelled_van_account_transactions_arr[] = $temp;
			}

		}

        return $temp_cancelled_van_account_transactions_arr;

    }

    public function saveToTempCancelledVanAccountCollections($temp_cancelled_van_account_collections_tbl, $cancelledVanAccountCollections, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_cancelled_van_account_collections_arr = [];
        
		foreach ($cancelledVanAccountCollections as $itemKey => $item) {

            $original_id = $item['id'];

            $where = [
                'company_id' => $selected_principal,
                'from_company_id' => $company_id,
                'original_id' => $original_id
            ];

            $doExist = $this->doExists( $where, $temp_cancelled_van_account_collections_tbl );
			
			if(!$doExist){

                $user_id = $this->getUserIdByUsername( $username );

                // $TempVanAccountCollection = $temp_cancelled_van_account_collections_tbl->newEntity();
                
                
                // $TempVanAccountCollection->cancelled_van_account_transaction_id = $item['cancelled_van_account_transaction_id'];
                // $TempVanAccountCollection->van_account_transaction_transaction_id = $item['van_account_transaction_transaction_id'];
                // $TempVanAccountCollection->payment_type = $item['payment_type'];
                // $TempVanAccountCollection->collected_amount = $item['collected_amount'];
                // $TempVanAccountCollection->date_collected = $item['date_collected'];
                // $TempVanAccountCollection->user_id = $user_id;
                // $TempVanAccountCollection->created = $date;
                // $TempVanAccountCollection->modified = $date;

                // $TempVanAccountCollection->original_id = $original_id;
                // $TempVanAccountCollection->from_company_id = $company_id;
                // $TempVanAccountCollection->company_id = $selected_principal;
                // $TempVanAccountCollection->transferred = 0;
                // $TempVanAccountCollection->transferred_id = 0;

                // $doInsert = $temp_cancelled_van_account_collections_tbl->save($TempVanAccountCollection);

				// if(!$doInsert){ 
                //     $result['message'] = "Unable to save canceled van account collections with transaction ID {$item['van_account_transaction_transaction_id']}";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['cancelled_van_account_transaction_id'] = $item['cancelled_van_account_transaction_id'];
                $temp['van_account_transaction_transaction_id'] = $item['van_account_transaction_transaction_id'];
                $temp['payment_type'] = $item['payment_type'];
                $temp['collected_amount'] = $item['collected_amount'];
                $temp['date_collected'] = $item['date_collected'];
                $temp['user_id'] = $user_id;
                $temp['created'] = $date;
                $temp['modified'] = $date;

                $temp['original_id'] = $original_id;
                $temp['from_company_id'] = $company_id;
                $temp['company_id'] = $selected_principal;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;

                
                $temp_cancelled_van_account_collections_arr[] = $temp;
			}
		}

		return $temp_cancelled_van_account_collections_arr;
    }

    public function saveToTempBeforeEditedVanAccountItems($temp_before_edited_van_account_items_tbl, $beforeEditedVanAccountItems, $company_id, $selected_principal)
    {
		$result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

       $temp_before_edited_van_account_items_arr = [];

		foreach ($beforeEditedVanAccountItems as $itemKey => $item) {
		
			$original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

            $doExist = $this->doExists( $where, $temp_before_edited_van_account_items_tbl );
			
			$productCode = $item['product_item_code'];
            $productUom = $item['uom'];


			if(!$doExist){

                // $TempBeforeEditedVanAccountItem = $temp_before_edited_van_account_items_tbl->newEntity();
                
                // $TempBeforeEditedVanAccountItem->edited_van_account_transactions_id = $item['edited_van_account_transactions_id'];
                // $TempBeforeEditedVanAccountItem->product_item_code = $item['product_item_code'];
                // $TempBeforeEditedVanAccountItem->uom = $item['uom'];
                // $TempBeforeEditedVanAccountItem->quantity = $item['quantity'];
                // $TempBeforeEditedVanAccountItem->stock_availability = $item['stock_availability'];
                // $TempBeforeEditedVanAccountItem->stock_weight = $item['stock_weight'];
                // $TempBeforeEditedVanAccountItem->price_with_tax = $item['price_with_tax'];
                // $TempBeforeEditedVanAccountItem->price_without_tax = $item['price_without_tax'];
                // $TempBeforeEditedVanAccountItem->van_account_transaction_transaction_id = $item['van_account_transaction_transaction_id'];
                // $TempBeforeEditedVanAccountItem->van_account_transaction_auto_invoice_number = $item['van_account_transaction_auto_invoice_number'];
                // $TempBeforeEditedVanAccountItem->edited_van_account_transaction_unique_id = $item['edited_van_account_transaction_unique_id'];
                // $TempBeforeEditedVanAccountItem->created = $date;
                // $TempBeforeEditedVanAccountItem->modified = $date;

                // $TempBeforeEditedVanAccountItem->company_id = $selected_principal;
                // $TempBeforeEditedVanAccountItem->original_id = $original_id;
                // $TempBeforeEditedVanAccountItem->from_company_id = $company_id;
                // $TempBeforeEditedVanAccountItem->transferred = 0;
                // $TempBeforeEditedVanAccountItem->transferred_id = 0;
                // $TempBeforeEditedVanAccountItem->counterpart_product_code = $counterpartProductCode;
                // $TempBeforeEditedVanAccountItem->counterpart_product_uom = $counterpartProductUom;

                // $doInsert = $temp_before_edited_van_account_items_tbl->save($TempBeforeEditedVanAccountItem);

				// if(!$doInsert){ 
                //     $result['hasError'] = true;   
                //     $result['message'] = "Unable Temp Before Edited Van Account Items with Van Account Transaction ID {$item['van_account_transaction_transaction_id']}";
				// 	continue;
				// }
                
                $counterpartProductCode = $this->getCounterpartCodes($company_id, $selected_principal, $productCode, 4);
                $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);

                $temp = [];

                $temp['edited_van_account_transactions_id'] = $item['edited_van_account_transactions_id'];
                $temp['product_item_code'] = $item['product_item_code'];
                $temp['uom'] = $item['uom'];
                $temp['quantity'] = $item['quantity'];
                $temp['stock_availability'] = $item['stock_availability'];
                $temp['stock_weight'] = $item['stock_weight'];
                $temp['price_with_tax'] = $item['price_with_tax'];
                $temp['price_without_tax'] = $item['price_without_tax'];
                $temp['van_account_transaction_transaction_id'] = $item['van_account_transaction_transaction_id'];
                $temp['van_account_transaction_auto_invoice_number'] = $item['van_account_transaction_auto_invoice_number'];
                $temp['edited_van_account_transaction_unique_id'] = $item['edited_van_account_transaction_unique_id'];
                $temp['created'] = $date;
                $temp['modified'] = $date;

                $temp['company_id'] = $selected_principal;
                $temp['original_id'] = $original_id;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_product_code'] = $counterpartProductCode;
                $temp['counterpart_product_uom'] = $counterpartProductUom;

                $temp_before_edited_van_account_items_arr[] = $temp;
			}
		}

		return $temp_before_edited_van_account_items_arr;

    }

    public function saveToTempAfterEditedVanAccountItems($temp_after_edited_van_account_items_tbl, $afterEditedVanAccountItems, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_after_edited_van_account_items_arr = [];

		foreach ($afterEditedVanAccountItems as $itemKey => $item) {

			$original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];
            
			$doExist = $this->doExists( $where, $temp_after_edited_van_account_items_tbl );
			
			$productCode = $item['product_item_code'];
			$productUom = $item['uom'];

			if(!$doExist){

                $counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);
                $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);


                // $TempAfterEditedVanAccountItem = $temp_after_edited_van_account_items_tbl->newEntity();

                
                // $TempAfterEditedVanAccountItem->edited_van_account_transactions_id = $item['edited_van_account_transactions_id'];
                // $TempAfterEditedVanAccountItem->product_item_code = $item['product_item_code'];
                // $TempAfterEditedVanAccountItem->uom = $item['uom'];
                // $TempAfterEditedVanAccountItem->quantity = $item['quantity'];
                // $TempAfterEditedVanAccountItem->stock_availability = $item['stock_availability'];
                // $TempAfterEditedVanAccountItem->stock_weight = $item['stock_weight'];
                // $TempAfterEditedVanAccountItem->price_with_tax = $item['price_with_tax'];
                // $TempAfterEditedVanAccountItem->price_without_tax = $item['price_without_tax'];
                // $TempAfterEditedVanAccountItem->van_account_transaction_transaction_id = $item['van_account_transaction_transaction_id'];
                // $TempAfterEditedVanAccountItem->van_account_transaction_auto_invoice_number = $item['van_account_transaction_auto_invoice_number'];
                // $TempAfterEditedVanAccountItem->edited_van_account_transaction_unique_id = $item['edited_van_account_transaction_unique_id'];
                // $TempAfterEditedVanAccountItem->created = $date;
                // $TempAfterEditedVanAccountItem->modified = $date;
                
                // $TempAfterEditedVanAccountItem->original_id = $original_id;
                // $TempAfterEditedVanAccountItem->company_id = $selected_principal;
                // $TempAfterEditedVanAccountItem->from_company_id = $company_id;
                // $TempAfterEditedVanAccountItem->transferred = 0;
                // $TempAfterEditedVanAccountItem->transferred_id = 0;
                // $TempAfterEditedVanAccountItem->counterpart_product_code = $counterpartProductCode;
                // $TempAfterEditedVanAccountItem->counterpart_product_uom = $counterpartProductCode;
                
                // if( !$temp_after_edited_van_account_items_tbl->save($TempAfterEditedVanAccountItem) ) {
                //     $result['message'][]= "Unable to Save Temp After Edited Van Account Items with {}";
                //     $result['hasError'] = false;
                //     continue;
                // }

                $temp = [];

                $temp['edited_van_account_transactions_id'] = $item['edited_van_account_transactions_id'];
                $temp['product_item_code'] = $item['product_item_code'];
                $temp['uom'] = $item['uom'];
                $temp['quantity'] = $item['quantity'];
                $temp['stock_availability'] = $item['stock_availability'];
                $temp['stock_weight'] = $item['stock_weight'];
                $temp['price_with_tax'] = $item['price_with_tax'];
                $temp['price_without_tax'] = $item['price_without_tax'];
                $temp['van_account_transaction_transaction_id'] = $item['van_account_transaction_transaction_id'];
                $temp['van_account_transaction_auto_invoice_number'] = $item['van_account_transaction_auto_invoice_number'];
                $temp['edited_van_account_transaction_unique_id'] = $item['edited_van_account_transaction_unique_id'];
                $temp['created'] = $date;
                $temp['modified'] = $date;
                
                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_product_code'] = $counterpartProductCode;
                $temp['counterpart_product_uom'] = $counterpartProductCode;

                $temp_after_edited_van_account_items_arr[] = $temp;
			}
		}
        
		return $temp_after_edited_van_account_items_arr;
    }

    public function saveToTempVarCollections($temp_var_collections_tbl, $varCollections, $company_id, $selected_principal)
    {
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_var_collections_arr = [];

		foreach ($varCollections as $itemKey => $item) {

            $orignal_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $orignal_id,
                'company_id' => $selected_principal
            ];

            $doExists = $this->doExists( $where, $temp_var_collections_tbl );

			if(!$doExist){
			
                $accountCode = $item['account_code'];
                $counterpartAccountCode = $this->getCounterpartCodes($source_company_id, $company_id, $accountCode, 1);
    
                //skip iteration
                if($counterpartAccountCode == ""){
                    $result['message'][] = "Temp Var Collections has no counterpart Account Code for {$accountCode}";
                    $result['hasError']++;
                    continue;
                }

                // $TempVarCollection = $temp_var_collections_tbl->newEntity();
                
                // $TempVarCollection->unique_id = $item['unique_id'];
                // $TempVarCollection->user_id = $user_id;
                // $TempVarCollection->collector_name = $item['collector_name'];
                // $TempVarCollection->account_code = $item['account_code'];
                // $TempVarCollection->account_name = $item['account_name'];
                // $TempVarCollection->amount_to_be_paid_w_tax_w_o_discount = $item['amount_to_be_paid_w_tax_w_o_discount'];
                // $TempVarCollection->amount_to_be_paid_w_tax_w_discount = $item['amount_to_be_paid_w_tax_w_discount'];
                // $TempVarCollection->total_deduction_amount = $item['total_deduction_amount'];
                // $TempVarCollection->total_check_amount = $item['total_check_amount'];
                // $TempVarCollection->total_cash_amount = $item['total_cash_amount'];
                // $TempVarCollection->over_under_amount = $item['over_under_amount'];
                // $TempVarCollection->official_receipt_number = $item['official_receipt_number'];
                // $TempVarCollection->official_receipt_date = $item['official_receipt_date'];
                // $TempVarCollection->or_image = $item['or_image'];
                // $TempVarCollection->auto_invoice_numbers = $item['auto_invoice_numbers'];
                // $TempVarCollection->notes = $item['notes'];
                // $TempVarCollection->date = $date;
                // $TempVarCollection->date = $date;

                // $TempVarCollection->original_id = $original_id;
                // $TempVarCollection->company_id = $selected_principal;
                // $TempVarCollection->from_company_id = $company_id;
                // $TempVarCollection->transferred = 0;
                // $TempVarCollection->transferred_id = 0;
                // $TempVarCollection->counterpart_account_code = $counterpartAccountCode['code'];
				
                

				// if( !$temp_var_collections_tbl->save($TempVarCollection) ){ 
                //     $result['message'][] = "Temp Var Collections cannot be saved";
                //     $result['hasError']++;
				// 	continue;
				// }

                $temp = [];

                $temp['unique_id'] = $item['unique_id'];
                $temp['user_id'] = $user_id;
                $temp['collector_name'] = $item['collector_name'];
                $temp['account_code'] = $item['account_code'];
                $temp['account_name'] = $item['account_name'];
                $temp['amount_to_be_paid_w_tax_w_o_discount'] = $item['amount_to_be_paid_w_tax_w_o_discount'];
                $temp['amount_to_be_paid_w_tax_w_discount'] = $item['amount_to_be_paid_w_tax_w_discount'];
                $temp['total_deduction_amount'] = $item['total_deduction_amount'];
                $temp['total_check_amount'] = $item['total_check_amount'];
                $temp['total_cash_amount'] = $item['total_cash_amount'];
                $temp['over_under_amount'] = $item['over_under_amount'];
                $temp['official_receipt_number'] = $item['official_receipt_number'];
                $temp['official_receipt_date'] = $item['official_receipt_date'];
                $temp['or_image'] = $item['or_image'];
                $temp['auto_invoice_numbers'] = $item['auto_invoice_numbers'];
                $temp['notes'] = $item['notes'];
                $temp['date'] = $date;
                $temp['date'] = $date;

                $temp['original_id'] = $original_id;
                $temp['company_id'] = $selected_principal;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;
                $temp['counterpart_account_code'] = $counterpartAccountCode['code'];
				
                $temp_var_collections_arr[] = $temp;
			}
		}

		return $temp_var_collections_arr;
    }

    public function saveToTempVanAccountCollections ($temp_van_account_collections_tbl, $vanAccountCollections, $company_id, $selected_principal)
    {
        
        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_collections_arr = [];

		foreach ($vanAccountCollections as $itemKey => $item) {

            $original_id = $item['id'];

            $where = [
                'from_company_id' => $company_id,
                'original_id' => $original_id
            ];

            $doExist = $this->doExists( $where, $temp_van_account_collections_tbl );
			
			if(!$doExist){

                // $temp_van_account_collection = $temp_van_account_collections_tbl->newEntity();
                
                // $temp_van_account_collection->van_account_transaction_id = $item['id'];
                // $temp_van_account_collection->van_account_transcation_transaction_id = $item['van_account_transcation_transaction_id'];
                // $temp_van_account_collection->auto_official_receipt = $item['auto_official_receipt'];
                // $temp_van_account_collection->payment_type = $item['payment_type'];
                // $temp_van_account_collection->collected_amount = $item['collected_amount'];
                // $temp_van_account_collection->date_collected = $item['date_collected'];
                // $temp_van_account_collection->user_id = $item['user_id'];
                // $temp_van_account_collection->anti_fraud_message = $item['anti_fraud_message'];
                // $temp_van_account_collection->created = $date;
                // $temp_van_account_collection->modified = $date;


                // $temp_van_account_collection->original_id = $original_id;
                // $temp_van_account_collection->from_company_id = $company_id;
                // $temp_van_account_collection->transferred = 0;
                // $temp_van_account_collection->transferred_id = 0;


				// if( !$temp_van_account_collections_tbl->save($temp_van_account_collection) ){ 
                //     $result['message'] = "Van Account Transaction Collections With Transaction ID {$item['van_account_transcation_transaction_id']} cannot be saved.";
                //     $result['hasError'] = true;
				// 	continue;
				// }

                $temp = [];

                $temp['van_account_transaction_id'] = $item['id'];
                $temp['van_account_transcation_transaction_id'] = $item['van_account_transcation_transaction_id'];
                $temp['auto_official_receipt'] = $item['auto_official_receipt'];
                $temp['payment_type'] = $item['payment_type'];
                $temp['collected_amount'] = $item['collected_amount'];
                $temp['date_collected'] = $item['date_collected'];
                $temp['user_id'] = $item['user_id'];
                $temp['anti_fraud_message'] = $item['anti_fraud_message'];
                $temp['created'] = $date;
                $temp['modified'] = $date;


                $temp['original_id'] = $original_id;
                $temp['from_company_id'] = $company_id;
                $temp['transferred'] = 0;
                $temp['transferred_id'] = 0;

                $temp_van_account_collections_arr[] = $temp;

			}
		}

		return $temp_van_account_collections_arr;
    }

    public function saveToTempVanAccountItems($temp_van_account_items_tbl,$vanAccountItems, $company_id, $selected_principal)
    {  
        

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

        $temp_van_account_items_items = [];

		foreach ($vanAccountItems as $itemKey => $item) {
            
			$original_id = $item['id'];

            $condition = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $this->doExists( $condition, $temp_van_account_items_tbl );
    
			$productCode = $item['product_code'];
			$productUom = $item['uom'];
            
			if(!$doExist){
                
                // $temp_van_account_items = $temp_van_account_items_tbl->newEntity();

                // $temp_van_account_items->van_account_transaction_id = $item['id'];
                // $temp_van_account_items->van_account_transaction_transaction_id = $item['transaction_id'];
                // $temp_van_account_items->product_item_code = $item['product_code'];
                // $temp_van_account_items->uom = $item['uom'];
                // $temp_van_account_items->quantity = $item['quantity'];
                // $temp_van_account_items->stock_availability = $item['stock_availability'];
                // $temp_van_account_items->stock_weight = $item['stock_weight'];
                // $temp_van_account_items->price_with_tax = $item['price_with_tax'];
                // $temp_van_account_items->price_without_tax = $item['price_without_tax'];
                // $temp_van_account_items->created = $date;
                // $temp_van_account_items->modified = $date;
                
                // $temp_van_account_items->original_id = $original_id;
                // $temp_van_account_items->company_id = $selected_principal;
                // $temp_van_account_items->from_company_id = $company_id;
                // $temp_van_account_items->transferred = 0;
                // $temp_van_account_items->transferred_id = 0;
                // $temp_van_account_items->counterpart_product_code = $counterpartProductCode;
                // $temp_van_account_items->counterpart_product_uom = $counterpartProductUom;

                $counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);
			    $counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);

                $temp_van_account_item = [];

                $temp_van_account_item['van_account_transaction_id']= $item['id'];
                $temp_van_account_item['van_account_transaction_transaction_id'] = $item['transaction_id'];
                $temp_van_account_item['product_item_code'] = $item['product_code'];
                $temp_van_account_item['uom'] = $item['uom'];
                $temp_van_account_item['quantity'] = $item['quantity'];
                $temp_van_account_item['stock_availability'] = $item['stock_availability'];
                $temp_van_account_item['stock_weight'] = $item['stock_weight'];
                $temp_van_account_item['price_with_tax'] = $item['price_with_tax'];
                $temp_van_account_item['price_without_tax'] = $item['price_without_tax'];
                $temp_van_account_item['created'] = $date;
                $temp_van_account_item['modified'] = $date;
                
                $temp_van_account_item['original_id'] = $original_id;
                $temp_van_account_item['company_id'] = $selected_principal;
                $temp_van_account_item['from_company_id'] = $company_id;
                $temp_van_account_item['transferred'] = 0;
                $temp_van_account_item['transferred_id'] = 0;
                $temp_van_account_item['counterpart_product_code'] = $counterpartProductCode;
                $temp_van_account_item['counterpart_product_uom'] = $counterpartProductUom;

                $temp_van_account_items_items[] = $temp_van_account_item;

			}

		}

		return $temp_van_account_items_items;
    }
    public function saveToTempVanAccountItems_backup($temp_van_account_items_tbl,$vanAccountItems, $company_id, $selected_principal)
    {  
        

        $result = [];
        $result['message'] = [];
        $result['hasError'] = false;
        $date = date('Y-m-d H:i:s');

      

		foreach ($vanAccountItems as $itemKey => $item) {
            
			$original_id = $item['id'];

            $condition = [
                'from_company_id' => $company_id,
                'original_id' => $original_id,
                'company_id' => $selected_principal
            ];

			$doExist = $this->doExists( $condition, $temp_van_account_items_tbl );
    
			$productCode = $item['product_code'];
			$productUom = $item['uom'];
            
			$counterpartProductCode = $this->getCounterpartProductCode($company_id, $selected_principal, $productCode, 4);
			$counterpartProductUom = $this->getCounterpartProductUom($company_id, $selected_principal, $productCode, $productUom);
        
			if(!$doExist){
                
                $temp_van_account_items = $temp_van_account_items_tbl->newEntity();

                $temp_van_account_items->van_account_transaction_id = $item['id'];
                $temp_van_account_items->van_account_transaction_transaction_id = $item['transaction_id'];
                $temp_van_account_items->product_item_code = $item['product_code'];
                $temp_van_account_items->uom = $item['uom'];
                $temp_van_account_items->quantity = $item['quantity'];
                $temp_van_account_items->stock_availability = $item['stock_availability'];
                $temp_van_account_items->stock_weight = $item['stock_weight'];
                $temp_van_account_items->price_with_tax = $item['price_with_tax'];
                $temp_van_account_items->price_without_tax = $item['price_without_tax'];
                $temp_van_account_items->created = $date;
                $temp_van_account_items->modified = $date;
                
                $temp_van_account_items->original_id = $original_id;
                $temp_van_account_items->company_id = $selected_principal;
                $temp_van_account_items->from_company_id = $company_id;
                $temp_van_account_items->transferred = 0;
                $temp_van_account_items->transferred_id = 0;
                $temp_van_account_items->counterpart_product_code = $counterpartProductCode;
                $temp_van_account_items->counterpart_product_uom = $counterpartProductUom;

                if( !$temp_van_account_items_tbl->save($temp_van_account_items) ) {
                    $result['message'][] = "Van Account Items With Product Code {$product_code} AND Van Account Transaction ID {$item['van_account_transaction_transaction_id']} cannot be saved.";
                    $result['hasError'] = true;
                    //$connection->rollback();
                } 
                // else {
                //     $connection->commit();
                // }

			}

		}

		return $result;
    }

    public function getAllProductCode( $company_id, $multi_principal_principal_product_settings_tbl )
    {
        $productCodes = $multi_principal_principal_product_settings_tbl->find()
        ->select(['item_code'])->where(['company_id' => $company_id]);

        $codes = [];
        foreach ( $productCodes as $key => $value ) {
           $arr = [];
           $arr[] = $value['item_code'];
           $codes = $arr;
        }
    
        return isset($codes) ? $codes : [];

    }

    public function getAllVaof( $start_date, $end_date, $company_id, $current_page, $assigned_principal_products )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_transaction_tbl = TableRegistry::get('van_account_transactions', ['connection' => $connection]);
        $multi_principal_user_settings_tbl = TableRegistry::get('multiPrincipalUserSettings', ['connection' => $connection]);

        $listVanAccountTransactions = array();
		$transactionIds = array();
        $principal = isset($_POST['principal']) ? $_POST['principal'] : '';
                
        $usernames = $multi_principal_user_settings_tbl->getAllUsername($company_id);
        $usernames = $multi_principal_user_settings_tbl->getValidatedUser( $company_id, $principal, $usernames );
        $branch_codes = $this->getValidatedBranches($company_id, $principal);
        $account_codes = $this->getValidatedAccounts($company_id, $principal);
        $product_codes = $this->getMappedAndAssignedProducts( $company_id, $principal );
    
        $count_username = count( $usernames );
        $count_branch_code = count( $branch_codes );
        $count_account_codes = count( $account_codes );
        $count_product_codes = count( $product_codes );
             
        $group = [
            // 'van_account_transactions.visit_number'
            'van_account_transactions.transaction_id'
        ];

        $where = [
            "van_account_transactions.transaction_date BETWEEN '". $start_date." 00:00:00' AND '". $end_date ." 23:59:59'",
            'van_account_transactions.company_id' => $company_id,
            "van_account_transactions.username IN" => $usernames,
            'van_account_transactions.branch_code IN' => $branch_codes,
            'van_account_transactions.account_code IN' => $account_codes,
            // 'VanAccountItem.product_code IN' => $product_codes,
        ];
        
        $joinVanAccountItems = [
            'table' => 'van_account_items',
            'alias' => 'VanAccountItem',
            'type' => 'INNER',
            'conditions' => array(
                'VanAccountItem.transaction_id = van_account_transactions.transaction_id',
            )
        ];

        $joinMultiPrincipalProductSet = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'MultiPrincipalPrincipalProductSetting.item_code = VanAccountItem.product_code',
                'MultiPrincipalPrincipalProductSetting.status' => 1,
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id
            )
        ];

        $listVanAccountTransactions = $van_account_transaction_tbl->find()
                                    // ->group($group)
                                    ->where($where)
                                    // ->join($joinVanAccountItems)
                                    // ->join($joinMultiPrincipalProductSet)
                                    ->offset($current_page)
                                    ->limit(1)
                                    ->toArray();
                                                          
        foreach ( $listVanAccountTransactions as $key => $transaction ) {
  
            $var_collection_unique_id = $transaction['var_collection_unique_id'];
            $transactionId = $transaction['transaction_id'];
            $vanAccountTransactionId = $transaction['transaction_id'];
            $poNumber = $transaction['po_number'];
            $vanAccountId = $transaction['id'];

            $vanAccountCollection = $this->getVaofVanAccountCollection( $company_id, $transactionId ); 
            $vanAccountItems = $this->getVaofVanAccountItem($company_id, $vanAccountTransactionId, $product_codes);
            $varCollection = $this->getVaofVarCollection( $company_id, $var_collection_unique_id ); 
            $afterEditedVanAccountItem = $this->getVaofAfterEditedVanAccountItem($company_id, $vanAccountTransactionId, $product_codes);
            $beforeEditedVanAccountItem = $this->getVaofBeforeEditedVanAccountItem($company_id, $vanAccountTransactionId, $product_codes);
            $cancelledVanAccountCollection = $this->getVaofCancelledVanAccountCollection($company_id, $vanAccountTransactionId);
            $cancelledVanAccountTransaction = $this->getVaofCancelledVanAccountTransaction($company_id, $poNumber);
            $cancelledVanAccountTransactionItem = $this->getVaofCancelledVanAccountTransactionItem($company_id, $vanAccountTransactionId); 
            $editedVanAccountTransaction = $this->getVaofEditedVanAccountTransaction($company_id, $poNumber, $vanAccountTransactionId); 
			$vanAccountNotOrderedItem = $this->getVaofVanAccountNotOrderedItem($company_id, $vanAccountId, $product_codes); // new online id
			$vanAccountServedProducts = $this->getVaofVanAccountServedProducts($company_id, $vanAccountId); // new online id
			$vanAccountTransactionDeduction = $this->getVaofVanAccountTransactionDeduction($company_id, $vanAccountTransactionId);// new online id
			$vanAccountTransactionEditCode = $this->getVaofVanAccountTransactionEditCode($company_id, $vanAccountId); // new online id
			$vanAccountTransactionEditRequest = $this->getVaofVanAccountTransactionEditRequest($company_id, $vanAccountTransactionId); // new online id
      
            $transaction['VanAccountCollections'] = $vanAccountCollection;
			$transaction['VanAccountItems'] = $vanAccountItems;
			$transaction['VarCollections'] = $varCollection;
			$transaction['AfterEditedVanAccountItems'] = $afterEditedVanAccountItem;
			$transaction['BeforeEditedVanAccountItems'] = $beforeEditedVanAccountItem;
			$transaction['CancelledVanAccountCollections'] = $cancelledVanAccountCollection;
			$transaction['CancelledVanAccountTransactions'] = $cancelledVanAccountTransaction;
			$transaction['CancelledVanAccountTransactionItems'] = $cancelledVanAccountTransactionItem;
			$transaction['EditedVanAccountTransactions'] = $editedVanAccountTransaction;
			$transaction['VanAccountNotOrderedItems'] = $vanAccountNotOrderedItem;
			$transaction['VanAccountServedProducts'] = $vanAccountServedProducts;
			$transaction['VanAccountTransactionDeductions'] = $vanAccountTransactionDeduction;
			$transaction['VanAccountTransactionEditCodes'] = $vanAccountTransactionEditCode;
			$transaction['VanAccountTransactionEditRequests'] = $vanAccountTransactionEditRequest;
        }                               


		return $listVanAccountTransactions;		

    }

    public function getVaofVanAccountTransactionEditRequest( $company_id, $vanAccountId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_transaction_edit_requests_tbl = TableRegistry::get('van_account_transaction_edit_requests', ['connection' => $connection]);
        
        return $van_account_transaction_edit_requests_tbl->find()
                                               ->where([
                                                    'van_account_transaction_id	' => $vanAccountId, 
                                                    'company_id' => $company_id
                                               ]);                                  

    }

    public function getVaofVanAccountTransactionEditCode( $company_id, $vanAccountId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_transaction_edit_codes_tbl = TableRegistry::get('van_account_transaction_edit_codes', ['connection' => $connection]);

        return $van_account_transaction_edit_codes_tbl->find()
                                            ->where([
                                                'van_account_transaction_id' => $vanAccountId,
                                                'company_id' => $company_id
                                            ]);                                 
    }

    public function getVaofVanAccountTransactionDeduction( $company_id, $transactionTransactionId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_transaction_deductions_tbl = TableRegistry::get('van_account_transaction_deductions', ['connection' => $connection]);

        return $van_account_transaction_deductions = $van_account_transaction_deductions_tbl->find()
                                        ->where([
                                            'van_account_transaction_transaction_id' => $transactionTransactionId,
                                            'company_id' => $company_id
                                        ]);
    }

    public function getVaofVanAccountServedProducts( $company_id, $vanAccountId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_served_products_tbl = TableRegistry::get('van_account_served_products', ['connection' => $connection]);

        $where = [
            'van_account_transaction_id' => $vanAccountId, 
            'company_id' => $company_id
        ];
        
        $join = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'VanAccountServedProduct.product_item_code = MultiPrincipalPrincipalProductSetting.item_code',
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
                'MultiPrincipalPrincipalProductSetting.status' => 1
            )
        ];
        
        return $van_account_served_products = $van_account_served_products_tbl->find()
                                    ->where($where);
    }

    public function getVaofVanAccountNotOrderedItem ( $company_id, $vanAccountId, $product_codes )
    {   
        $connection = ConnectionManager::get('client_' . $company_id);
        $van_account_not_ordered_items_tbl = TableRegistry::get('VanAccountNotOrderedItems', ['connection' => $connection]);

        $where = [
            'van_account_transaction_online_id' => $vanAccountId,
            'product_item_code IN' => $product_codes
        ];

        $join = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
                'VanAccountNotOrderedItems.product_item_code = MultiPrincipalPrincipalProductSetting.item_code',
                'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
                'MultiPrincipalPrincipalProductSetting.status' => 1
            )
        ];

        return $van_account_not_ordered_items = $van_account_not_ordered_items_tbl->find()
                                        ->where($where);

    }

    public function getVaofEditedVanAccountTransaction ( $company_id, $poNumber, $transaction_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $edited_van_account_transactions_tbl = TableRegistry::get('edited_van_account_transactions', ['connection' => $connection]);

        return $edited_van_account_transactions = $edited_van_account_transactions_tbl->find()
                                         ->where([
                                            'transaction_id' => $transaction_id, 
                                            'company_id' => $company_id
                                         ]);                             
    }

    public function getVaofCancelledVanAccountTransactionItem( $company_id, $transactionTransactionId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $cancelled_van_account_transaction_items_tbl = TableRegistry::get('cancelled_van_account_transaction_items', ['connection' => $connection]);
        
        $where = array(
            'van_account_transaction_transaction_id' => $transactionTransactionId
        );

        $join = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'MultiPrincipalPrincipalProductSetting',
            'type' => 'INNER',
            'conditions' => array(
            'CancelledVanAccountTransactionItem.product_item_code = MultiPrincipalPrincipalProductSetting.item_code',
            'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
            'MultiPrincipalPrincipalProductSetting.status' => 1
            )
        ];

        return $cancelled_van_account_transaction_items = $cancelled_van_account_transaction_items_tbl->find()
                                                  ->where($where);                       
    }

    public function getVaofCancelledVanAccountTransaction( $company_id, $transaction_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $cancelled_van_account_transactions_tbl = TableRegistry::get('cancelled_van_account_transactions', ['connection' => $connection]);
       
		return $cancelled_van_account_transactions_tbl->find()->where(['transaction_id' => $transaction_id, 'company_id' => $company_id]);
    }

    public function getVaofCancelledVanAccountCollection ( $company_id, $transactionTransactionId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $cancelled_van_account_transactions_tbl = TableRegistry::get('cancelled_van_account_collections', ['connection' => $connection]);

		return $cancelledVanAccountCollection = $cancelled_van_account_transactions_tbl->find()
                                    ->where([
                                        'van_account_transaction_transaction_id' => $transactionTransactionId
                                    ]);
    }

    public function getVaofBeforeEditedVanAccountItem( $company_id, $transactionTransactionId, $assigned_products )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $before_edited_van_account_items_tbl = TableRegistry::get('before_edited_van_account_items', ['connection' => $connection]);

		return $beforeEditedVanAccountItem = $before_edited_van_account_items_tbl->find()
                                    ->where([
                                        'van_account_transaction_transaction_id' => $transactionTransactionId,
				                        'company_id' => $company_id,
				                        'product_item_code IN' => $assigned_products
                                    ]);
    }

    public function getVaofAfterEditedVanAccountItem ( $company_id, $transactionTransactionId, $assigned_products )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $after_edited_van_account_items_tbl = TableRegistry::get('after_edited_van_account_items', ['connection' => $connection]);

        return $afterEditedVanAccountItem = $after_edited_van_account_items_tbl->find()
                                    ->where([
                                        'van_account_transaction_transaction_id' => $transactionTransactionId,
				                        'company_id' => $company_id,
				                        'product_item_code IN' => $assigned_products    
                                    ]);
    }
    
    public function getVaofVarCollection ( $company_id, $var_collection_unique_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $vanAccountCollectionsTbl = TableRegistry::get('var_collections', ['connection' => $connection]);

		return $varCollection = $vanAccountCollectionsTbl->find()
                        ->where([
                            'unique_id' => $var_collection_unique_id
                        ]);
    }

    public function getVaofVanAccountItem ( $company_id, $transactionId, $product_codes )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $vanAccountItemsTbl = TableRegistry::get('van_account_items', ['connection' => $connection]);
  
		$where = [
            'transaction_id' => $transactionId,
            'product_code IN' => $product_codes
        ];

        $join = [
            'table' => 'multi_principal_principal_product_settings',
			'alias' => 'MultiPrincipalPrincipalProductSetting',
			'type' => 'INNER',
			'conditions' => array(
		  	    'van_account_items.product_code = MultiPrincipalPrincipalProductSetting.item_code',
			    'MultiPrincipalPrincipalProductSetting.company_id' => $company_id,
				'MultiPrincipalPrincipalProductSetting.status' => 1
            )
            
        ];

        return $vanAccountItems = $vanAccountItemsTbl->find()
                         ->join($join)
                         ->where($where); 
		
    }

    public function getVaofVanAccountCollection( $company_id, $transactionId )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $vanAccountCollectionsTbl = TableRegistry::get('van_account_collections', ['connection' => $connection]);

        return $vanAccountCollection = $vanAccountCollectionsTbl->find()
                              ->where(['transaction_id' => $transactionId ]);                

    }

    public function getValidatedUsers( $company_id, $principal_company_id )
    {
        $default_connection = ConnectionManager::get('default');

        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_user_settings_table = TableRegistry::get('multi_principal_user_settings', ['connection' => $connection]);

        $distributor_users = [];

        $distributor_query = $multi_principal_user_settings_table->find()
                            ->select(['username' => 'multi_principal_user_settings.username'])
                            ->join([
                                'table' => 'multi_principal_uploaded_codes',
                                'alias' => 'multi_principal_uploaded_codes',
                                'type' => 'INNER',
                                'conditions' => array(
                                    'multi_principal_uploaded_codes.code = multi_principal_user_settings.username',
                                    'multi_principal_uploaded_codes.deleted_status' => 0
                                )
                            ])
                            ->where([
                                'multi_principal_user_settings.status' => 1,
                                'multi_principal_user_settings.company_id' => $company_id,
                                'multi_principal_uploaded_codes.company_id' => $company_id,
                                'multi_principal_uploaded_codes.to_company_id' => $principal_company_id,
                            ]);

        foreach ( $distributor_query as $key => $value ) {
            $distributor_users[] = $value['username'];
        }
        

        return $distributor_users;
    }

    public function getValidatedAccounts( $company_id, $principal_company_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_uploaded_codes_table = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $distributor_accounts = [];

        $distributor_query = $multi_principal_uploaded_codes_table->find()
                            ->where([
                                'type' => 1,
                                'deleted_status' => 0,
                                'company_id' => $company_id,
                                'to_company_id' => $principal_company_id,
                            ]);

        foreach ( $distributor_query as $key => $value ) {
            $distributor_accounts[] = $value['counterpart_code'];
        }
        

        return $distributor_accounts;
    }

    public function getValidatedNoOrderInputs( $company_id, $principal_company_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_uploaded_codes_table = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $distributor_no_order_inputs = [];

        $distributor_query = $multi_principal_uploaded_codes_table->find()
                            ->where([
                                'type' => 5,
                                'deleted_status' => 0,
                                'company_id' => $company_id,
                                'to_company_id' => $principal_company_id,
                            ]);

        foreach ( $distributor_query as $key => $value ) {
            $distributor_no_order_inputs[] = $value['counterpart_code'];
        }
        

        return $distributor_no_order_inputs;
    }

    public function getValidatedBranches( $company_id, $principal_company_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_uploaded_codes_table = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);

        $distributor_accounts = [];
        $distributor_query = $multi_principal_uploaded_codes_table->find()
                            ->where([
                                'type' => 2,
                                'deleted_status' => 0,
                                'company_id' => $company_id,
                                'to_company_id' => $principal_company_id,
                            ]);

        foreach ( $distributor_query as $key => $value ) {
            $distributor_accounts[] = $value['counterpart_code'];
        }
        

        return $distributor_accounts;
    }

    public function getMappedAndAssignedProducts( $company_id, $principal_company_id )
    {
        $connection = ConnectionManager::get('client_' . $company_id);
        $multi_principal_uploaded_codes_table = TableRegistry::get('multi_principal_uploaded_codes', ['connection' => $connection]);
        $product_uom_code_mappings_table = TableRegistry::get('product_uom_code_mappings', ['connection' => $connection]);
        
        $distributor_product_code = [];

        $multi_principal_principal_product_settings = [
            'table' => 'multi_principal_principal_product_settings',
            'alias' => 'multi_principal_principal_product_settings',
            'type' => 'INNER',
            'conditions' => array(
                'product_uom_code_mappings.counterpart_code = multi_principal_principal_product_settings.item_code'
            )
        ];

        $distributor_query = $product_uom_code_mappings_table->find()
                            ->distinct(['product_uom_code_mappings.counterpart_code'])
                            ->join($multi_principal_principal_product_settings)
                            ->where([
                                'product_uom_code_mappings.deleted' => 0, 
                                'product_uom_code_mappings.company_id' => $company_id,
                                'product_uom_code_mappings.to_company_id' => $principal_company_id,
                                'multi_principal_principal_product_settings.status' => 1
                            ]);

        foreach ( $distributor_query as $key => $value ) {
            $distributor_product_code[] = $value['counterpart_code'];
        }
        
        return $distributor_product_code;

    }

    public function viewTransferedData()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 

        $function = $_POST['functions'];
        $principal_company_id = $_POST['principal_company_id'];
        $start_date = $_POST['start_date']. " 00:00:00";
        $end_date = $_POST['end_date']. " 23:59:59";
        
        switch( $function ) {
            
            case "baof";
                $temp_table = $this->setSource('TempSalesOrders', $principal_company_id );

                $query = $temp_table->find('all');
                $query->where(function (QueryExpression $exp, Query $q) use( $company_id, $principal_company_id,  $start_date, $end_date){
            
                    $exp->eq('company_id', $principal_company_id);
                    $exp->eq('from_company_id', $company_id);
                    $exp->eq('transferred', 1);
                    $exp->between('created', $start_date, $end_date);
                    return $exp;
                });
        
            break;

            case "vaof";
                $filter_by = $_POST['filter_by'];
                $temp_table = $this->setSource('TempVanAccountTransactions', $principal_company_id );
        
                $query = $temp_table->find('all');
                $query->where(function (QueryExpression $exp, Query $q) use( $company_id, $principal_company_id,  $start_date, $end_date, $filter_by){
            
                    $exp->eq('company_id', $principal_company_id);
                    $exp->eq('from_company_id', $company_id);
                    $exp->eq('transferred', 1);

                    if( $filter_by == 'transaction_date' ) {
                        $exp->between('transaction_date', $start_date, $end_date);
                    } else {
                        $exp->between('transferred_date', $start_date, $end_date);
                    }

                    return $exp;
                });
                
            break;

            case "visit_status_logs";
                $temp_table = $this->setSource('TempNoOrderInputs', $principal_company_id );
        
                $query = $temp_table->find('all');
                $query->where(function (QueryExpression $exp, Query $q) use( $company_id, $principal_company_id,  $start_date, $end_date){
            
                    $exp->eq('company_id', $principal_company_id);
                    $exp->eq('from_company_id', $company_id);
                    $exp->eq('transferred', 1);
                    $exp->between('transferred_date', $start_date, $end_date);
                    return $exp;
                });
                
            break;
        }
        
        $count = $query->count();
        echo $count;
        exit;
    }

    public function viewBaofTranferredData()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 
        
        $multi_principal_uploaded_codes_table = $this->setSource('multi_principal_uploaded_codes', $company_id );
       
        $data_table = $multi_principal_uploaded_codes_table->viewBaofTranferredData( $company_id );
        echo json_encode( $data_table );
        exit;
    }

    public function viewVaofTranferredData()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 

        $multi_principal_uploaded_codes_table = $this->setSource('multi_principal_uploaded_codes', $company_id );

        $data_table = $multi_principal_uploaded_codes_table->viewVaofTranferredData( $company_id );
        echo json_encode( $data_table );
        exit;
    }

    public function viewVisitLogsTranferredData()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 

        $multi_principal_uploaded_codes_table = $this->setSource('multi_principal_uploaded_codes', $company_id );

        $data_table = $multi_principal_uploaded_codes_table->viewVisitLogsTranferredData( $company_id );
        echo json_encode( $data_table );
        exit;
    }

    public function getUserIDByUsername( $username )
    {
        $connection = ConnectionManager::get('default');
        $users_table = TableRegistry::get('users', ['connection' => $connection]);

        $data = $users_table->find()
                ->where([
                    'username' => $username
                ])
                ->first();

        return $data['id'];        
    }

    public function getUsernameByUserId( $userId )
    {
        $connection = ConnectionManager::get('default');
        $users_table = TableRegistry::get('users', ['connection' => $connection]);

        $data = $users_table->find()
                ->where([
                    'id' => $userId
                ])
                ->first();

        return $data['username'];        
    }

    public function getBranchNameByBranchCode( $branch_code, $company_id )
    {
        $branches_table = $this->setSource('branches', $company_id);

        $data = $branches_table->find()
                ->where([
                    'code' => $branch_code,
                    'company_id' => $company_id
                ])
                ->first();

        return $data['name'];
    }

    public function getAccountNameByAccountCode( $account_code, $company_id )
    {
        $accounts_table = $this->setSource('accounts', $company_id);

        $data = $accounts_table->find()
                ->where([
                    'code' => $account_code,
                    'company_id' => $company_id
                ])
                ->first();

        return $data['name'];
    }

    public function getProductNameByProductCode( $product_code, $company_id )
    {
        $products_table = $this->setSource('products', $company_id);

        $data = $products_table->find()
                ->where([
                    'code' => $product_code,
                    'company_id' => $company_id
                ])
                ->first();

        return !empty($data['name']) ? $data['name'] : "No Product Name";
    }

    public function getCounterpartUserId( $username )
    {
        $conn = ConnectionManager::get('default');
        $users_Table = TableRegistry::get('users', ['connection' => $conn ]);

        return $users_Table->find()->where(['username' => $username ])->first();
    }

    public function DeleteAll()
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id']; 

        $principal = $_POST['principal'];
        $function_name = $_POST['function_name'];

        // TYPES :
        // account = 1, 
        // branch = 2, 
        // warehouse = 3, 
        // products = 4,
        // reason = 5, 
        // field_ex = 6, 
        // field_ex_elements = 7, 
        // field_ex_standard = 8, 
        // booking_approval_status = 9, 
        // user_mapping = 10

        switch( $function_name ) {
            case "account_codes";
                $type = 1;
            break;
            case "branch_codes";
                $type = 2;
            break;
            case "warehouse_codes";
                $type = 3;
            break;
            case "product_codes";
                $type = 4;
            break;
            case "visit_status_codes";
                $type = 5;
            break;
            case "field_execution_forms";
                $type = 6;
            break;
            case "field_execution_elements";
                $type = 7;
            break;
            case "field_execution_standards";
                $type = 8;
            break;
            case "user_mapping";
                $type = 10;
            break;
        }

        $result = $this->DeleteAllCodeMappingByTypes( $type, $principal );

        echo json_encode( $result );
        exit;
    }


    public function DeleteAllCodeMappingByTypes( $type, $principal_company_id )
    {
        $user = $this->Auth->user();
        $company_id = $user['company_id'];

        $multi_principal_uploaded_codes_table = $this->setSource('multi_principal_uploaded_codes', $company_id);
        $product_uom_code_mappings_table = $this->setSource('product_uom_code_mappings', $company_id);

        $result = [];
        $result['success'] = true;
        $result['message'] = "Successfully Deleted";
        
        if( $type == 4 ) {
            $set = ['deleted' => 1, 'modified' => date('Y-m-d')];
            $condition = ['to_company_id' => $principal_company_id ];
            $update = $product_uom_code_mappings_table->updateAll( $set, $condition );
        } else {
            $set = ['deleted_status' => 1, 'date_modified' => date('Y-m-d')];
            $condition = ['type' => $type, 'to_company_id' => $principal_company_id ];
            $update = $multi_principal_uploaded_codes_table->updateAll( $set, $condition );
        }

        if( !$update ) {
            $result['success'] = false;
            $result['message'] = "Query Error.";
        }

        return $result;
    }
}
