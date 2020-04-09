if(document.getElementById("LayerPayNow")){

    window.onload = function(){
        trigger_layer()
    }

    document.getElementById("LayerPayNow").onclick = function () {

        trigger_layer()
    }


}

function trigger_layer() {

    Layer.checkout(
        {
            token: layer_params.payment_token_id,
            accesskey: layer_params.accesskey
        },
        function (response) {
            console.log(response)
            if(response !== null || response.length > 0 ){

                if(response.paymentId !== undefined){

                    document.getElementById('layer_payment_id').value = response.paymentId;

                }

            }

            document.layer_payment_int_form.submit();
        },
        function (err) {
            alert(err.message);
        }
    );
}
