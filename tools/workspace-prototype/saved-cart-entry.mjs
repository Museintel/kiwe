/** A notification requests a review; it never silently narrows the collection. */
export function savedCartEntry(search){
 const value=new URLSearchParams(search).get('cart'),id=value&&/^[1-9]\d*$/.test(value)?Number(value):0;
 return {reviewId:Number.isSafeInteger(id)?id:0,query:{status:'abandoned',sort:'newest',page:1,id:0}};
}
