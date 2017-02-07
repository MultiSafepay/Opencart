<?php
class ControllerExtensionTotalMultiSafepayPayAfterFee extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/total/multisafepaypayafterfee');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

	if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$status = true;

			$this->model_setting_setting->editSetting('multisafepaypayafterfee', array_merge($this->request->post, array('multisafepaypayafterfee_status' => $status)));

			$this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=total', 'SSL'));
		}


		$data['heading_title'] = $this->language->get('heading_title');
		
		$data['text_edit'] = $this->language->get('text_edit');
		$data['text_enabled'] = $this->language->get('text_enabled');
		$data['text_disabled'] = $this->language->get('text_disabled');
		$data['text_none'] = $this->language->get('text_none');

		$data['entry_total'] = $this->language->get('entry_total');
		$data['entry_fee'] = $this->language->get('entry_fee');
		$data['entry_tax_class'] = $this->language->get('entry_tax_class');
		$data['entry_status'] = $this->language->get('entry_status');
		$data['entry_sort_order'] = $this->language->get('entry_sort_order');

		$data['button_save'] = $this->language->get('button_save');
		$data['button_cancel'] = $this->language->get('button_cancel');

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], 'SSL')
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_total'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=total', 'SSL')
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/total/multisafepaypayafterfee', 'token=' . $this->session->data['token'], 'SSL')
		);

		$data['action'] = $this->url->link('extension/total/multisafepaypayafterfee', 'token=' . $this->session->data['token'], 'SSL');

		$data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=total', 'SSL');

		
		if (isset($this->request->post['multisafepaypayafterfee'])) {
			$data['multisafepaypayafterfee'] = $this->request->post['multisafepaypayafterfee'];
		} else {
			$data['multisafepaypayafterfee'] = $this->config->get('multisafepaypayafterfee');
		}

		$this->load->model('localisation/tax_class');




$data['countries'] = array();



		$data['countries'][] = array(
			'name' => $this->language->get('text_netherlands'),
			'code' => 'NLD'
		);


		$data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/total/multisafepaypayafterfee.tpl', $data));
	}

	
	
	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/total/multisafepaypayafterfee')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}
}