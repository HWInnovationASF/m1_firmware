(function(){
  var busy=false,done=false;
  function esc(value){var node=document.createElement('div');node.textContent=value==null?'':String(value);return node.innerHTML}
  function device(info){return esc(info.device||'อุปกรณ์อื่น')+(info.ip?' · IP '+esc(info.ip):'')+(info.at?'<br><small>'+esc(info.at.replace('T',' ').slice(0,19))+'</small>':'')}
  function alertUser(options){if(window.Swal)return Swal.fire(options);alert(String(options.title||'')+'\n'+String(options.text||''));return Promise.resolve()}
  function check(){
    if(busy||done||document.hidden)return;busy=true;
    fetch('./session_check.php?_='+Date.now(),{cache:'no-store',credentials:'same-origin'}).then(function(response){return response.json()}).then(function(data){
      busy=false;
      if(data.s==='ok'){
        if(data.note)alertUser({icon:'info',title:'มีการเข้าสู่ระบบซ้อน',html:'ระบบนำ session เดิมออกแล้ว<br><br>'+device(data.note),confirmButtonText:'ตกลง'});
        return;
      }
      done=true;
      var kicked=data.s==='kicked';
      alertUser({icon:'warning',allowOutsideClick:false,allowEscapeKey:false,confirmButtonText:'ตกลง',title:kicked?'ถูกออกจากระบบ':'Session สิ้นสุดแล้ว',html:kicked?'บัญชี <b>'+esc(data.info.user)+'</b> ถูกเข้าสู่ระบบจากอุปกรณ์อื่น<br><br>'+device(data.info):'กรุณาเข้าสู่ระบบใหม่'}).then(function(){location.href='./index.php'});
    }).catch(function(){busy=false});
  }
  setInterval(check,30000);document.addEventListener('visibilitychange',function(){if(!document.hidden)check()});check();
})();
