(function(){
  'use strict';
  const PLATE_REGEX = /^[A-Z0-9]{2,4}[\s\-]?[A-Z0-9]{3,4}$/i;

  function isControlKey(e) {
    const controlKeys = ['Backspace','Tab','Enter','Escape','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Delete','Home','End'];
    if (controlKeys.includes(e.key)) return true;
    if (e.ctrlKey || e.metaKey || e.altKey) return true;
    return false;
  }

  function sanitizeValue(val){
    val = (val || '').toUpperCase();
    // allow only letters, digits, space and hyphen
    val = val.replace(/[^A-Z0-9 -]/g,'');
    // collapse whitespace and hyphens
    val = val.replace(/\s+/g,' ');
    val = val.replace(/-+/g,'-');
    // trim
    val = val.replace(/^[\s-]+|[\s-]+$/g,'');
    // if pattern like ABCdigits without separator, insert a space after 3 letters
    if (/^[A-Z]{3}\d/.test(val)) {
       val = val.slice(0,3) + ' ' + val.slice(3);
    }
    // limit letters to max 3 at start
    val = val.replace(/^([A-Z]{3})[A-Z]+/, '$1');
    // limit digits after separator to 4
    val = val.replace(/([\s-])([^\s-]*)/, function(m, sep, rest){
       const digits = (rest || '').replace(/\D/g,'').slice(0,4);
       return sep + digits;
    });
    return val;
  }

  function attachValidators(root=document){
    const selector = 'input.plate-input, input[name="plate_number"], input[name="plateNo"]';
    const inputs = Array.from((root || document).querySelectorAll(selector));
    inputs.forEach(input=>{
       // ensure class exists
       if (!input.classList.contains('plate-input')) input.classList.add('plate-input');

       // add invalid-feedback if missing
       const next = input.nextElementSibling;
       if (!(next && next.classList && next.classList.contains('invalid-feedback'))){
         const fb = document.createElement('div');
         fb.className = 'invalid-feedback';
         fb.innerText = 'Invalid plate format. Use ABC 1234 or ABC-1234.';
         input.insertAdjacentElement('afterend', fb);
       }

       input.addEventListener('keydown', function(e){
         if (isControlKey(e)) return;
         if (e.key.length === 1 && !/^[A-Za-z0-9 \-]$/.test(e.key)) {
            e.preventDefault();
         }
       });

       input.addEventListener('input', function(){
          const old = input.value || '';
          const cleaned = sanitizeValue(old);
          if (cleaned !== old) {
            input.value = cleaned;
            // move caret to end
            try { input.setSelectionRange(input.value.length, input.value.length); } catch(e){}
          } else {
            // enforce uppercase
            const up = old.toUpperCase();
            if (up !== old) input.value = up;
          }
          input.classList.remove('is-invalid');
       });

       input.addEventListener('keyup', function(){
          const v = input.value || '';
          if (v !== v.toUpperCase()) input.value = v.toUpperCase();
       });

       input.addEventListener('blur', function(){
           const v = (input.value || '').trim();
           if (v === '') { input.classList.remove('is-invalid'); return; }
           if (!PLATE_REGEX.test(v)) {
              input.classList.add('is-invalid');
           } else {
              input.classList.remove('is-invalid');
           }
       });

       input.addEventListener('paste', function(e){
          e.preventDefault();
          const text = (e.clipboardData || window.clipboardData).getData('text') || '';
          const cleaned = sanitizeValue(text);
          input.value = cleaned;
          input.dispatchEvent(new Event('input', {bubbles:true}));
          input.dispatchEvent(new Event('blur', {bubbles:true}));
       });
    });
  }

  document.addEventListener('DOMContentLoaded', function(){ attachValidators(document); });

  // expose helper for dynamic content
  window.PM = window.PM || {};
  window.PM.attachPlateValidators = attachValidators;
})();
