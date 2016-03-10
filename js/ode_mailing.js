CRM.$(function ($) {
    $( document ).ajaxComplete(function(event, xhr, settings) {
	if (settings.type === 'POST') {
	    var mailing = event.currentTarget.location.hash;
	    if (!((mailing.indexOf('mailing') > -1) || (mailing.indexOf('abtest') > -1))) {
		return false;
	    }
	    var type = 'Mailing';
	    var mailingId = xhr.responseJSON.id
	    if (mailing.indexOf('abtest') > -1) {
		type = 'MailingAB';		
		CRM.vars.odevariables.mailings[mailingId] = false;
	    }
	    
	    if (CRM.vars.odevariables.type && 
	       (!mailingId || settings.data.indexOf('entity=' + type + '&action=create') < 0)) 
	    { return false; }
	    
	    CRM.vars.odevariables.type = type;
	    
	    if (CRM.vars.odevariables.mailings[mailingId]) {
		return false;
	    }
	    
	    var isFieldPresent = false;
	    $(CRM.vars.odevariables.fromFields[type]).each(function(index, value) {
		if ($("select[name='" + value +"']").length) {
		    if ($.inArray($("select[name='" + value + "']").val(), CRM.vars.odevariables.fromAddress)) {
			$("select[name='" + value + "']").select2('val', '');
		    }
		    $("select[name='" + value + "'] option").each(function() {
			if($.inArray(this.value, CRM.vars.odevariables.fromAddress)) {
			    $(this).remove();
			    isFieldPresent = true;
			}
		    });
		    $("select[name='" + value + "']").change();
		}		 
	    });
	    if (isFieldPresent) {
		CRM.alert(CRM.vars.odevariables.msg, 'Notice');
		CRM.vars.odevariables.mailings[mailingId] = true;
	    }
	}
    });
});