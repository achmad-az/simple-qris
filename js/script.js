jQuery(document).ready(function($) {
    let statusCheckInterval;
    
    $('#qris-payment-form').on('submit', function(e) {
        e.preventDefault();
        
        const amount = $('#amount').val();
        
        if (amount < 1000) {
            alert('Minimum amount is IDR 1,000');
            return;
        }
        
        $('.qris-submit-btn').prop('disabled', true).text('Generating...');
        
        $.ajax({
            url: qrisAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'generate_qris',
                nonce: qrisAjax.nonce,
                amount: amount
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Generate QR code
                    $('#qris-code').html('');
                    new QRCode(document.getElementById('qris-code'), {
                        text: data.qr_string,
                        width: 256,
                        height: 256
                    });
                    
                    $('.qris-amount').text('Amount: IDR ' + data.amount);
                    $('.qris-status').text('Status: Waiting for payment');
                    
                    // Start status checking
                    if (statusCheckInterval) {
                        clearInterval(statusCheckInterval);
                    }
                    
                    statusCheckInterval = setInterval(function() {
                        checkPaymentStatus(data.external_id);
                    }, 5000);
                    
                    // Start countdown timer (15 minutes)
                    let timeLeft = 15 * 60;
                    const timerInterval = setInterval(function() {
                        const minutes = Math.floor(timeLeft / 60);
                        const seconds = timeLeft % 60;
                        $('.qris-timer').text(`Time remaining: ${minutes}:${seconds < 10 ? '0' : ''}${seconds}`);
                        
                        if (timeLeft <= 0) {
                            clearInterval(timerInterval);
                            clearInterval(statusCheckInterval);
                            $('#qris-result').hide();
                            alert('QRIS code has expired. Please generate a new one.');
                        }
                        
                        timeLeft--;
                    }, 1000);
                    
                    $('#qris-result').show();
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('Failed to generate QRIS code. Please try again.');
            },
            complete: function() {
                $('.qris-submit-btn').prop('disabled', false).text('Generate QRIS');
            }
        });
    });
    
    function checkPaymentStatus(external_id) {
        $.ajax({
            url: qrisAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'check_payment_status',
                nonce: qrisAjax.nonce,
                external_id: external_id
            },
            success: function(response) {
                if (response.success) {
                    const status = response.data.status;
                    $('.qris-status').text('Status: ' + status);
                    
                    if (status === 'COMPLETED') {
                        clearInterval(statusCheckInterval);
                        alert('Payment successful!');
                    } else if (status === 'FAILED') {
                        clearInterval(statusCheckInterval);
                        alert('Payment failed. Please try again.');
                    }
                }
            }
        });
    }
});