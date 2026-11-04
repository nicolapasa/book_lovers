import {request,t} from './http.js';

export async function importCodes(form,libraryId){
    const field=form.elements.codes;
    const codes=field.value.split(';').map(value=>value.trim()).filter(Boolean);
    if(codes.length<1||codes.length>20)throw new Error(t('Inserisci da 1 a 20 codici.','Enter from 1 to 20 codes.'));
    const results=document.getElementById('import-results');
    results.replaceChildren();field.disabled=true;
    const submit=form.querySelector('[type="submit"]');
    try{
        for(let i=0;i<codes.length;i++){
            submit.textContent=t('Importazione','Importing')+` ${i+1}/${codes.length}…`;
            const row=document.createElement('div');row.className='import-result';row.textContent=codes[i]+' — '+t('Ricerca in corso…','Searching…');results.appendChild(row);
            try{const result=await request(`/libraries/${libraryId}/import`,'POST',{code:codes[i]});row.textContent=result.title+' — '+(result.added?t('Aggiunto','Added'):t('Già nella libreria','Already in your library'));}catch(error){row.textContent=codes[i]+' — '+error.message;}
            if(i<codes.length-1)await new Promise(resolve=>setTimeout(resolve,1100));
        }
    }finally{field.disabled=false;submit.textContent=t('Importa nella libreria','Import to library');}
}
