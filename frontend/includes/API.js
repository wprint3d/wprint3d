import Backend from './Backend';

export default ({
    get:    (url, data)  => Backend.get(`/api${url}`,  data),
    post:   (url, data)  => Backend.post(`/api${url}`, data),
    delete: (url, data)  => Backend.delete(`/api${url}`, data),
    put:    (url, data)  => Backend.put(`/api${url}`,  data)
});