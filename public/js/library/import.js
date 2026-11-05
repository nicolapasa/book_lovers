import {request,t} from './http.js';

export async function importSelectedVolumes(form,libraryId){
    const selected=[...form.querySelectorAll('input[name="volume_ids[]"]:checked:not(:disabled)')];
    if(!selected.length)throw new Error(t('Seleziona almeno un libro.','Select at least one book.'));
    const controls=[...form.closest('dialog').querySelectorAll('input,select,button')];
    const states=controls.map(control=>control.disabled);
    const submit=form.querySelector('[type="submit"]');
    const label=submit.textContent;
    controls.forEach(control=>control.disabled=true);
    try{
        for(let i=0;i<selected.length;i++){
            const field=selected[i],row=field.closest('.search-result');
            const status=row.querySelector('[data-import-status]');
            submit.textContent=t('Importazione','Importing')+` ${i+1}/${selected.length}…`;
            status.textContent=t('Importazione in corso…','Importing…');
            try{
                const result=await request(`/libraries/${libraryId}/select`,'POST',{volume_id:field.value});
                field.checked=false;field.dataset.imported='true';
                status.textContent=result.added?t('Aggiunto alla libreria','Added to your library'):t('Già nella libreria','Already in your library');
            }catch(error){status.textContent=error instanceof TypeError?t('Connessione non disponibile. Riprova.','Connection unavailable. Please retry.'):error.message;}
            if(i<selected.length-1)await new Promise(resolve=>setTimeout(resolve,1100));
        }
    }finally{
        controls.forEach((control,index)=>control.disabled=states[index]);
        for(const field of selected.filter(field=>field.dataset.imported)){
            field.disabled=true;field.closest('.search-result').querySelector('[data-action="select-volume"]').disabled=true;
        }
        submit.textContent=label;
    }
}

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
