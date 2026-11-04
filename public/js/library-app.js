import {request,t,locale,setLocale} from './library/http.js';
import {importCodes} from './library/import.js';

const app=document.getElementById('app');
const modal=document.getElementById('modal');
let pageState={library_id:Number(document.getElementById('main')?.dataset.library)||null};
let lastDialog=null,importing=false,toastTimer;
let loadSequence=0;

function toast(text){const el=document.getElementById('toast');el.textContent=text;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),4500);}
function errorText(error){return error instanceof TypeError?t('Connessione non disponibile. Riprova.','Connection unavailable. Please retry.'):error.message;}

async function load(url=new URL(location.href),push=false){
    const sequence=++loadSequence;
    const result=await request('/view?'+url.searchParams);
    if(sequence!==loadSequence)return;
    pageState=result;app.innerHTML=result.html;
    document.documentElement.lang=locale();document.title='Book Lovers — '+result.title;
    if(push)history.pushState({},'',url);
}
function pageUrl(page){const url=new URL('/',location.origin);url.searchParams.set('view',page);if(pageState.library_id)url.searchParams.set('library',pageState.library_id);return url;}
async function dialog(kind,entry=null){
    const params=new URLSearchParams({kind});
    if(pageState.library_id)params.set('library',pageState.library_id);
    if(entry)params.set('entry',entry);
    try{
        const result=await request('/dialog?'+params);modal.innerHTML=result.html;lastDialog={kind,entry};
        if(!modal.open)modal.showModal();
    }catch(error){if(error.status===401&&kind!=='login')return dialog('login');throw error;}
}
async function refreshDialog(){await load();if(modal.open&&lastDialog)await dialog(lastDialog.kind,lastDialog.entry);}

document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-action]');if(!button||button.disabled)return;
    const {action,id,value}=button.dataset;
    try{
        if(action==='close'){if(!importing)modal.close();return;}
        if(importing&&modal.contains(button))return;
        if(action==='menu'){button.setAttribute('aria-expanded',String(document.querySelector('.sidebar').classList.toggle('open')));return;}
        if(action==='dialog'){await dialog(button.dataset.kind,button.dataset.entry);return;}
        if(action==='lang'){setLocale(button.dataset.lang);await load();return;}
        if(action==='nav'){await load(pageUrl(button.dataset.view),true);window.scrollTo(0,0);return;}
        if(action==='visit'){const url=pageUrl('public');url.searchParams.set('library',id);await load(url,true);window.scrollTo(0,0);return;}
        if(action==='filter'){const url=new URL(location.href);url.searchParams.set(button.dataset.key,value);await load(url,true);return;}
        if(action==='rating'){
            const form=button.closest('form');form.elements.rating.value=button.dataset.rating;
            for(const star of form.querySelectorAll('[data-rating]')){star.classList.toggle('selected',Number(star.dataset.rating)<=Number(button.dataset.rating));star.setAttribute('aria-pressed',String(star===button));}return;
        }
        if(action==='mark-read'){const field=document.getElementById('progress');field.value=100;document.getElementById('progress-value').textContent='100%';return;}
        if(action==='copy'){try{await navigator.clipboard.writeText(button.dataset.url);toast(t('Link copiato.','Link copied.'));}catch{toast(t('Seleziona e copia il link qui sopra.','Select and copy the link above.'));}return;}
        button.disabled=true;
        if(action==='logout'){await request('/logout','POST',{});modal.close();await load(new URL('/',location.origin),true);return;}
        if(action==='visibility'){await request(`/libraries/${pageState.library_id}`,'PATCH',{is_public:value==='true'});await refreshDialog();return;}
        if(action==='affiliate'){const result=await request(`/libraries/${id}/affiliations`,'POST',{});toast(result.status==='accepted'?t('Sei già affiliato.','You are already connected.'):t('Richiesta registrata.','Request recorded.'));return;}
        if(action==='respond'){await request(`/affiliations/${id}`,'PATCH',{status:value});await load();return;}
        if(action==='book-like'||action==='review-like'){await request(action==='book-like'?`/entries/${id}/like`:`/reviews/${id}/like`,'PUT',{liked:value==='true'});await refreshDialog();return;}
        if(action==='suspend'||action==='hide'){await request(action==='suspend'?`/admin/users/${id}`:`/admin/libraries/${id}`,'PATCH',action==='suspend'?{suspended:value==='true'}:{hidden:value==='true'});await load();return;}
        if(action==='select-volume'){const result=await request(`/libraries/${pageState.library_id}/select`,'POST',{volume_id:id});await load();await dialog('detail',result.id);toast(t('Edizione aggiunta alla libreria.','Edition added to your library.'));return;}
    }catch(error){if(error.status===401){await dialog('login');}else toast(errorText(error));}finally{button.disabled=false;}
});

document.addEventListener('submit',async event=>{
    const form=event.target.closest('form[data-form]');if(!form)return;event.preventDefault();if(form.dataset.busy)return;
    form.dataset.busy='true';const submit=form.querySelector('[type="submit"]');if(submit)submit.disabled=true;
    const error=form.querySelector('.form-error');if(error)error.textContent='';
    try{
        const type=form.dataset.form;
        const fields=Object.fromEntries(new FormData(form));
        if(type==='filter'){const url=new URL(location.href);if(!['library','public'].includes(url.searchParams.get('view')||'library'))url.searchParams.set('view','library');url.searchParams.set('q',fields.q);await load(url,true);return;}
        if(type==='register'||type==='login'){await request('/'+type,'POST',{...fields,locale:locale()});modal.close();await load(new URL('/',location.origin),true);toast(t('Benvenuto nella tua libreria.','Welcome to your library.'));return;}
        if(type==='library'){const result=await request('/libraries','POST',fields);modal.close();const url=pageUrl('library');url.searchParams.set('library',result.id);await load(url,true);return;}
        if(type==='progress'){await request(`/entries/${form.dataset.entry}/progress`,'PATCH',{progress:Number(fields.progress)});await load();toast(t('Progresso salvato.','Progress saved.'));return;}
        if(type==='review'){if(!Number(fields.rating))throw new Error(t('Scegli da 1 a 5 stelle.','Choose from 1 to 5 stars.'));await request(`/entries/${form.dataset.entry}/review`,'PUT',{rating:Number(fields.rating),body:fields.body,locale:locale()});await refreshDialog();toast(t('Valutazione salvata.','Rating saved.'));return;}
        if(type==='import'){importing=true;await importCodes(form,pageState.library_id);await load();toast(t('Importazione completata. Controlla gli esiti.','Import complete. Check the results.'));return;}
        if(type==='search'){const result=await request('/books/search?'+new URLSearchParams(fields));document.getElementById('search-results').innerHTML=result.html;return;}
        if(type==='metadata'){const result=await request(form.dataset.entry?`/entries/${form.dataset.entry}/metadata`:`/libraries/${pageState.library_id}/books`,'POST',new FormData(form));await load();await dialog('detail',result.id);toast(t('Dati salvati nella tua libreria.','Details saved in your library.'));}
    }catch(exception){if(error)error.textContent=errorText(exception);else toast(errorText(exception));}finally{importing=false;delete form.dataset.busy;if(submit)submit.disabled=false;}
});
document.addEventListener('change',async event=>{const key=event.target.dataset.control;if(!key)return;try{const url=new URL(location.href);url.searchParams.set(key,event.target.value);if(key==='library')url.searchParams.set('view','library');await load(url,true);}catch(error){toast(errorText(error));}});
document.addEventListener('input',event=>{if(event.target.id==='progress')document.getElementById('progress-value').textContent=event.target.value+'%';});
modal.addEventListener('cancel',event=>{if(importing)event.preventDefault();});
window.addEventListener('popstate',()=>{if(modal.open)modal.close();load().catch(error=>toast(errorText(error)));});
load().catch(error=>toast(errorText(error)));
