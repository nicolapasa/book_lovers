let language=localStorage.getItem('book-lovers-language')||'it';
if(!['it','en'].includes(language))language='it';
let csrf=document.querySelector('meta[name="csrf-token"]').content;
export const locale=()=>language;
export const t=(it,en)=>language==='it'?it:en;
export function setLocale(value){language=value==='en'?'en':'it';localStorage.setItem('book-lovers-language',language);}
export async function request(path,method='GET',body){
    const multipart=body instanceof FormData;
    const response=await fetch('/app'+path,{method,credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,'X-Locale':language,...(!multipart?{'Content-Type':'application/json'}:{})},...(body===undefined?{}:{body:multipart?body:JSON.stringify(body)})});
    const data=await response.json().catch(()=>({}));
    if(data.csrf)csrf=data.csrf;
    if(!response.ok){
        const messages={book_not_found:t('Libro non trovato. Puoi inserirlo manualmente.','Book not found. You can add it manually.'),metadata_unavailable:t('Catalogo non disponibile. Riprova o inserisci il libro manualmente.','Catalog unavailable. Retry or add the book manually.'),google_books_unavailable:t('Google Books non è disponibile al momento. Puoi inserire il libro manualmente.','Google Books is currently unavailable. You can add the book manually.')};
        const fallback={401:t('Accedi per continuare.','Sign in to continue.'),403:t('Non hai i permessi per questa operazione.','You do not have permission for this action.'),404:t('Contenuto non disponibile.','Content unavailable.'),419:t('Sessione scaduta. Ricarica la pagina.','Session expired. Reload this page.'),429:t('Troppe richieste. Attendi un minuto e riprova.','Too many requests. Wait a minute and retry.')};
        const error=new Error(messages[data.error]||Object.values(data.errors||{}).flat()[0]||fallback[response.status]||t('Operazione non riuscita. Riprova.','Could not complete the action. Please retry.'));
        error.status=response.status;throw error;
    }
    return data;
}
